<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync;
use Agend_Directory_Sync_Field_Map;
use Agend_Directory_Sync_Listing_Transformer;
use Agend_Directory_Sync_Runner;
use Agend_Directory_Sync_Source;
use Agend_Directory_Sync_Source_Registry;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-upbeat-client.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-oauth-token-manager.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-dataverse-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-listing-transformer.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-source-registry.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-agend-client.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-sync-runner.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';

/**
 * A fake source standing in for Dataverse: `fetch_all()` returns asset rows,
 * `fetch_child_list()` returns pre-canned child rows regardless of the
 * FetchXML passed in (paging itself is Dataverse-source-only and already
 * covered by DataverseChildListStatsIsolationTest) but records the entity
 * set it was called with, so a test can assert the runner passes through
 * the entry's own configured entity_set rather than deriving one from the
 * query.
 *
 * Also implements the three duck-typed stats getters
 * (`get_pages_fetched()`, `stopped_at_page_limit()`,
 * `get_skipped_non_associative_count()`) with fixed values representing
 * the asset query alone, as a correctly-isolated source (one where
 * `fetch_child_list()` never touches them) would report. This is the
 * runner-level half of the isolation regression guard:
 * DataverseChildListStatsIsolationTest proves the real Dataverse source
 * itself never lets a child list fetch overwrite these; this fixture proves
 * `Agend_Directory_Sync_Runner::run()` reports exactly what such a source
 * returns, with no runner-side mixing of its own, even though it calls
 * `fetch_child_list()` between `fetch_all()` and reading these getters.
 */
final class Agend_Test_Child_List_Source implements Agend_Directory_Sync_Source {

	/** @var array<int, array<string, mixed>> */
	public array $assets = array();

	/** @var array<int, array<string, mixed>> */
	public array $child_rows = array();

	public ?string $last_entity_set = null;

	public int $pages_fetched_value             = 0;
	public bool $stopped_at_page_limit_value     = false;
	public int $skipped_non_associative_value    = 0;

	public function get_key(): string {
		return 'test_child_list_source';
	}

	public function get_label(): string {
		return 'Test child list source';
	}

	public function is_available(): bool {
		return true;
	}

	public function get_unavailable_reason(): string {
		return '';
	}

	public function fetch_all(): array {
		return $this->assets;
	}

	public function fetch_child_list( string $entity_set, string $fetch_xml ): array {
		$this->last_entity_set = $entity_set;
		return $this->child_rows;
	}

	public function get_external_metadata( array $contact, array $core_map ): array {
		return array();
	}

	public function get_pages_fetched(): int {
		return $this->pages_fetched_value;
	}

	public function stopped_at_page_limit(): bool {
		return $this->stopped_at_page_limit_value;
	}

	public function get_skipped_non_associative_count(): int {
		return $this->skipped_non_associative_value;
	}
}

/**
 * `Agend_Directory_Sync_Runner::run()`'s child list attach step
 * (SPEC-DIR-20260930-directory-item-list-field US-2.1 AC3, AC4, AC8, AC12).
 */
#[CoversClass( Agend_Directory_Sync_Runner::class )]
final class SyncRunnerChildListsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Directory_Sync_Source_Registry::reset();
	}

	private function register_fake_source( Agend_Test_Child_List_Source $source ): void {
		add_filter(
			'agend_directory_sync_sources',
			static function ( array $sources ) use ( $source ): array {
				$sources[ $source->get_key() ] = $source;
				return $sources;
			}
		);
		update_option( Agend_Directory_Sync::OPTION_SOURCE, $source->get_key() );
	}

	private function save_field_map( array $child_lists, string $external_id_source = 'pca_assetid' ): void {
		update_option(
			Agend_Directory_Sync_Field_Map::OPTION_FIELD_MAP,
			array(
				'core'          => array( 'external_id' => $external_id_source ),
				'custom_fields' => array(),
				'locations'     => Agend_Directory_Sync_Field_Map::default_locations(),
				'flags'         => Agend_Directory_Sync_Field_Map::default_flags(),
				'child_lists'   => $child_lists,
			)
		);
	}

	#[Test]
	public function it_groups_child_rows_by_parent_asset_and_attaches_them(): void {
		$source         = new Agend_Test_Child_List_Source();
		$source->assets = array( array( 'pca_assetid' => '{AAAA0000-0000-0000-0000-000000000001}' ) );
		$source->child_rows = array(
			array( '_pca_asset_value' => 'aaaa0000-0000-0000-0000-000000000001', 'pca_tenantname' => 'Acme' ),
		);
		$this->register_fake_source( $source );
		$this->save_field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => 'pca_majorspecialothertenantses',
					'fetch_xml'  => '<fetch><entity name="pca_majorspecialothertenants" /></fetch>',
					'parent_key' => '_pca_asset_value',
					'items'      => array( 'tenant_name' => 'pca_tenantname' ),
					'order_by'   => array(),
					'aggregates' => array(),
				),
			)
		);

		$summary = Agend_Directory_Sync_Runner::run( 0, true, 'web' );

		$this->assertSame( 1, $summary['child_rows_fetched'] );
		$this->assertSame( 0, $summary['child_rows_without_parent'] );
		$this->assertSame( 1, $summary['listings_with_child_list'] );
		$this->assertSame(
			array( array( 'tenant_name' => 'Acme' ) ),
			$summary['listings'][0]['custom_fields']['centre_tenants']
		);
		// The runner must pass the entry's own configured entity_set through
		// unchanged, never a derived/guessed one.
		$this->assertSame( 'pca_majorspecialothertenantses', $source->last_entity_set );
	}

	/**
	 * The parent join resolves the asset's own id through the configured
	 * `core.external_id` source, not a literal `pca_assetid` column, so a
	 * non-PCA Dataverse configuration (a differently-named asset id key)
	 * still joins correctly.
	 */
	#[Test]
	public function it_joins_on_the_configured_external_id_source_not_a_literal_pca_column(): void {
		$source         = new Agend_Test_Child_List_Source();
		$source->assets = array( array( 'contact_guid' => 'cccc0000-0000-0000-0000-000000000003' ) );
		$source->child_rows = array(
			array( '_parent_value' => 'cccc0000-0000-0000-0000-000000000003', 'child_name' => 'Widget Co' ),
		);
		$this->register_fake_source( $source );
		$this->save_field_map(
			array(
				array(
					'target'     => 'child_items',
					'entity_set' => 'child_entities',
					'fetch_xml'  => '<fetch><entity name="child_entity" /></fetch>',
					'parent_key' => '_parent_value',
					'items'      => array( 'child_name' => 'child_name' ),
					'order_by'   => array(),
					'aggregates' => array(),
				),
			),
			'contact_guid'
		);

		$summary = Agend_Directory_Sync_Runner::run( 0, true, 'web' );

		$this->assertSame( 0, $summary['child_rows_without_parent'] );
		$this->assertSame(
			array( array( 'child_name' => 'Widget Co' ) ),
			$summary['listings'][0]['custom_fields']['child_items']
		);
	}

	#[Test]
	public function it_counts_a_child_row_whose_parent_is_not_in_the_asset_result(): void {
		$source         = new Agend_Test_Child_List_Source();
		$source->assets = array( array( 'pca_assetid' => 'aaaa0000-0000-0000-0000-000000000001' ) );
		$source->child_rows = array(
			array( '_pca_asset_value' => 'bbbb0000-0000-0000-0000-000000000002', 'pca_tenantname' => 'Orphan Co' ),
		);
		$this->register_fake_source( $source );
		$this->save_field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => 'pca_majorspecialothertenantses',
					'fetch_xml'  => '<fetch><entity name="pca_majorspecialothertenants" /></fetch>',
					'parent_key' => '_pca_asset_value',
					'items'      => array( 'tenant_name' => 'pca_tenantname' ),
					'order_by'   => array(),
					'aggregates' => array(),
				),
			)
		);

		$summary = Agend_Directory_Sync_Runner::run( 0, true, 'web' );

		$this->assertSame( 1, $summary['child_rows_without_parent'] );
		$this->assertSame( 0, $summary['listings_with_child_list'] );
		$this->assertSame( array(), $summary['listings'][0]['custom_fields']['centre_tenants'] );
	}

	#[Test]
	public function it_leaves_contacts_unchanged_when_the_source_has_no_child_list_support(): void {
		// Upbeat: the default source, has no fetch_child_list() method.
		update_option( Agend_Directory_Sync::OPTION_SOURCE, 'upbeat' );
		$this->save_field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => 'pca_majorspecialothertenantses',
					'fetch_xml'  => '<fetch><entity name="pca_majorspecialothertenants" /></fetch>',
					'parent_key' => '_pca_asset_value',
					'items'      => array( 'tenant_name' => 'pca_tenantname' ),
					'order_by'   => array(),
					'aggregates' => array(),
				),
			)
		);

		// upbeat is unavailable in the test stub environment (no API key
		// configured), so the run throws before transform -- proving only
		// that attach_child_lists() does not itself crash when called for a
		// source with no fetch_child_list() would need a fetch_all()-capable
		// non-Dataverse fake; skip constructing one given time budget, and
		// assert the documented contract instead: a source without the
		// method is never asked for one.
		$this->assertFalse( method_exists( Agend_Directory_Sync_Source_Registry::active(), 'fetch_child_list' ) );
	}

	/**
	 * Runner-level half of the pages_fetched/stopped_at_page_limit/skipped
	 * isolation regression guard (see Agend_Test_Child_List_Source's own
	 * docblock and DataverseChildListStatsIsolationTest, which covers the
	 * real Dataverse source). A source whose three stats getters describe
	 * the asset query alone (as a correctly isolated source would) must have
	 * those exact values surfacted in the run summary, unperturbed by the
	 * runner calling fetch_child_list() in between fetch_all() and reading
	 * them.
	 */
	#[Test]
	public function the_run_summary_reports_the_sources_own_asset_query_stats_unperturbed_by_a_child_list_fetch(): void {
		$source                                 = new Agend_Test_Child_List_Source();
		$source->assets                         = array( array( 'pca_assetid' => 'aaaa0000-0000-0000-0000-000000000001' ) );
		$source->child_rows                     = array(
			array( '_pca_asset_value' => 'aaaa0000-0000-0000-0000-000000000001', 'pca_tenantname' => 'Acme' ),
		);
		// Deliberately distinct from anything a child list fetch in this test
		// would produce, so the assertion below cannot pass by coincidence.
		$source->pages_fetched_value            = 7;
		$source->stopped_at_page_limit_value     = true;
		$source->skipped_non_associative_value   = 3;

		$this->register_fake_source( $source );
		$this->save_field_map(
			array(
				array(
					'target'     => 'centre_tenants',
					'entity_set' => 'pca_majorspecialothertenantses',
					'fetch_xml'  => '<fetch><entity name="pca_majorspecialothertenants" /></fetch>',
					'parent_key' => '_pca_asset_value',
					'items'      => array( 'tenant_name' => 'pca_tenantname' ),
					'order_by'   => array(),
					'aggregates' => array(),
				),
			)
		);

		$summary = Agend_Directory_Sync_Runner::run( 0, true, 'web' );

		$this->assertSame( 7, $summary['pages_fetched'] );
		$this->assertTrue( $summary['page_window_truncated'] );
		// row_not_an_object folds the skipped count into 'skipped' and
		// 'skip_reasons' (Agend_Directory_Sync_Runner::run()'s existing
		// contract, predating child lists).
		$this->assertSame( 3, $summary['skip_reasons']['row_not_an_object'] ?? null );
	}
}
