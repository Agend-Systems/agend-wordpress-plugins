<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync;
use Agend_Directory_Sync_Dataverse_Source;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// The main plugin file is required for the OPTION_DATAVERSE constant; its
// bootstrap only registers hooks on `plugins_loaded`, which never fires here.
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
// The secret store subclass only defines itself once its parent exists.
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/class-agend-apps-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-secret-store.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-oauth-token-manager.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-dataverse-source.php';

/**
 * A child list query (`fetch_child_list()`) pages through the same
 * `paginate_query()` as the main asset query (`fetch_all()`), but must never
 * overwrite the instance-level stats (`get_pages_fetched()`,
 * `stopped_at_page_limit()`, `get_skipped_non_associative_count()`) the run
 * summary reads. A prior implementation had `paginate_query()` reset and
 * write those three instance properties on every call, so with any child
 * list configured `Agend_Directory_Sync_Runner::run()` (which calls
 * `attach_child_lists()` -- and therefore `fetch_child_list()` -- between
 * `fetch_all()` and reading those getters) reported the LAST child query's
 * page count and truncation state instead of the asset query's, masking a
 * truncated asset sync as complete (fixed alongside PR #57 review).
 */
#[CoversClass( Agend_Directory_Sync_Dataverse_Source::class )]
final class DataverseChildListStatsIsolationTest extends TestCase {

	private const ENVIRONMENT_URL = 'https://contoso.crm6.dynamics.com';
	private const ENTITY_SET      = 'contacts';
	private const FETCH_XML       = '<fetch><entity name="contact"><attribute name="contactid" /></entity></fetch>';
	private const TENANT_ID       = 'test-tenant';
	private const CLIENT_ID       = 'test-client';

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET' ) ) {
			define( 'AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET', 'test-secret' );
		}

		update_option(
			Agend_Directory_Sync::OPTION_DATAVERSE,
			array(
				'environment_url' => self::ENVIRONMENT_URL,
				'entity_set'      => self::ENTITY_SET,
				'fetch_xml'       => self::FETCH_XML,
				'tenant_id'       => self::TENANT_ID,
				'client_id'       => self::CLIENT_ID,
				// Shared by both the asset query and any child list query
				// (connection-level setting): the asset query below is made
				// to hit this cap; the child list query below finishes in
				// one page, well under it.
				'max_pages'       => 2,
			)
		);

		$token_url = Agend_Directory_Sync_Dataverse_Source::resolve_token_url( '', self::TENANT_ID );

		set_transient(
			'agend_dsync_oauth_' . md5( $token_url . '|' . self::CLIENT_ID ),
			array(
				'access_token' => 'test-token',
				'expires_at'   => time() + 3600,
			),
			3600
		);
	}

	private function source(): Agend_Directory_Sync_Dataverse_Source {
		$source = new Agend_Directory_Sync_Dataverse_Source();

		$this->assertTrue( $source->is_available(), $source->get_unavailable_reason() );

		return $source;
	}

	#[Test]
	public function fetch_child_list_does_not_overwrite_the_asset_querys_stats(): void {
		$source = $this->source();

		// Asset query: two pages, hitting the configured max_pages=2 cap
		// while Dataverse still reports more records available, so this
		// query's own stopped_at_page_limit is TRUE.
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array( array( 'contactid' => 'asset-1' ) ),
				Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS => true,
			)
		);
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array( array( 'contactid' => 'asset-2' ) ),
				Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS => true,
			)
		);

		$assets = $source->fetch_all();

		$this->assertCount( 2, $assets );
		$this->assertSame( 2, $source->get_pages_fetched() );
		$this->assertTrue( $source->stopped_at_page_limit() );
		$this->assertSame( 0, $source->get_skipped_non_associative_count() );

		// Child list query: one page, well under the shared max_pages cap,
		// Dataverse reports no more records (stopped_at_page_limit for THIS
		// query is false), and one row is not a JSON object (skipped count
		// for THIS query is 1) -- every one of these differs from the asset
		// query's own numbers above, which is what makes the isolation
		// assertion below meaningful rather than coincidental.
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array( array( '_pca_asset_value' => 'asset-1', 'pca_tenantname' => 'Acme' ), 'not-an-object' ),
				Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS => false,
			)
		);

		$child_rows = $source->fetch_child_list( 'pca_majorspecialothertenantses', '<fetch><entity name="pca_majorspecialothertenants" /></fetch>' );

		$this->assertCount( 1, $child_rows );

		// The asset query's stats must survive the child list call unchanged.
		$this->assertSame( 2, $source->get_pages_fetched() );
		$this->assertTrue( $source->stopped_at_page_limit() );
		$this->assertSame( 0, $source->get_skipped_non_associative_count() );
	}
}
