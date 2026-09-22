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
 * Field value discovery for the guided secondary filter: the values actually
 * in use on records (preferred), falling back to or merged with the field's
 * metadata options, cached so the admin page can call this repeatedly while
 * a filter is being built.
 *
 * Every request is driven through {@see Agend_Test_WP::queue_response()}; no
 * real network call or secret is needed because
 * `AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET` is defined once for the
 * whole suite (so `is_available()`'s secret check passes without touching
 * the encrypted store, which needs `wp_salt()` -- not stubbed in this
 * harness) and the OAuth access token is seeded directly into the transient
 * cache the token manager reads, so `get_access_token()` never has to
 * acquire one.
 */
#[CoversClass( Agend_Directory_Sync_Dataverse_Source::class )]
final class DataverseFieldValuesTest extends TestCase {

	private const ENVIRONMENT_URL = 'https://contoso.crm6.dynamics.com';
	private const ENTITY_SET      = 'contacts';
	private const FETCH_XML       = '<fetch><entity name="contact"><attribute name="contactid" /></entity></fetch>';
	private const TENANT_ID       = 'test-tenant';
	private const CLIENT_ID       = 'test-client';

	protected function setUp(): void {
		parent::setUp();

		// A wp-config.php constant satisfies is_available()'s secret check
		// without exercising the encrypted store's wp_salt()-derived key,
		// which this unit harness does not stub.
		if ( ! defined( 'AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET' ) ) {
			define( 'AGEND_DIRECTORY_SYNC_DATAVERSE_CLIENT_SECRET', 'test-secret' );
		}

		$this->seedSettingsAndToken();
	}

	private function seedSettingsAndToken(): void {
		update_option(
			Agend_Directory_Sync::OPTION_DATAVERSE,
			array(
				'environment_url' => self::ENVIRONMENT_URL,
				'entity_set'      => self::ENTITY_SET,
				'fetch_xml'       => self::FETCH_XML,
				'tenant_id'       => self::TENANT_ID,
				'client_id'       => self::CLIENT_ID,
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
	public function it_pairs_formatted_values_with_raw_values_from_the_in_use_query(): void {
		Agend_Test_WP::queue_response( 200, array( 'AttributeType' => 'Picklist' ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array(
					array(
						'agend_value' => 798380003,
						'agend_value@OData.Community.Display.V1.FormattedValue' => 'Region North',
					),
					array(
						'agend_value' => 798380004,
						'agend_value@OData.Community.Display.V1.FormattedValue' => 'Region South',
					),
				),
			)
		);
		// The picklist metadata call this source always attempts alongside
		// the in-use query, returning nothing new to merge.
		Agend_Test_WP::queue_response( 200, array( 'LogicalName' => 'pca_membergroup' ) );

		$result = $this->source()->fetch_field_values( 'pca_membergroup' );

		$this->assertSame( 'contact', $result['entity'] );
		$this->assertSame( 'pca_membergroup', $result['field'] );
		$this->assertSame( 'picklist', $result['type'] );
		$this->assertSame( 'in_use', $result['source'] );
		$this->assertFalse( $result['has_more'] );
		$this->assertSame(
			array(
				array( 'value' => '798380003', 'label' => 'Region North' ),
				array( 'value' => '798380004', 'label' => 'Region South' ),
			),
			$result['options']
		);

		$this->assertCount( 3, Agend_Test_WP::$requests );
		$this->assertStringContainsString( "Attributes(LogicalName='pca_membergroup')", Agend_Test_WP::$requests[0]['url'] );
		$this->assertStringContainsString( 'AttributeType', Agend_Test_WP::$requests[0]['url'] );

		$in_use_request = Agend_Test_WP::$requests[1];
		$this->assertStringContainsString( 'fetchXml=', $in_use_request['url'] );
		$decoded_fetch_xml = rawurldecode( substr( $in_use_request['url'], strpos( $in_use_request['url'], 'fetchXml=' ) + 9 ) );
		$this->assertStringContainsString( 'aggregate="true"', $decoded_fetch_xml );
		$this->assertStringContainsString( 'groupby="true"', $decoded_fetch_xml );
		$this->assertStringContainsString( 'name="pca_membergroup"', $decoded_fetch_xml );
		$this->assertSame(
			'odata.include-annotations="OData.Community.Display.V1.FormattedValue"',
			$in_use_request['headers']['Prefer'] ?? null
		);

		$this->assertStringContainsString( 'Microsoft.Dynamics.CRM.PicklistAttributeMetadata', Agend_Test_WP::$requests[2]['url'] );
	}

	#[Test]
	public function it_falls_back_to_metadata_when_the_in_use_aggregate_fails(): void {
		Agend_Test_WP::queue_response( 200, array( 'AttributeType' => 'Picklist' ) );
		Agend_Test_WP::queue_response( 400, array( 'error' => array( 'code' => '0x1', 'message' => 'boom' ) ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'OptionSet' => array(
					'Options' => array(
						array(
							'Value' => 1,
							'Label' => array( 'UserLocalizedLabel' => array( 'Label' => 'Region North' ) ),
						),
						array(
							'Value' => 2,
							'Label' => array( 'UserLocalizedLabel' => array( 'Label' => 'Region South' ) ),
						),
					),
				),
			)
		);

		$result = $this->source()->fetch_field_values( 'pca_membergroup' );

		$this->assertSame( 'metadata', $result['source'] );
		$this->assertSame(
			array(
				array( 'value' => '1', 'label' => 'Region North' ),
				array( 'value' => '2', 'label' => 'Region South' ),
			),
			$result['options']
		);
	}

	#[Test]
	public function it_merges_metadata_options_after_in_use_options(): void {
		Agend_Test_WP::queue_response( 200, array( 'AttributeType' => 'MultiSelectPicklist' ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array(
					array( 'agend_value' => 1, 'agend_value@OData.Community.Display.V1.FormattedValue' => 'Alpha' ),
					array( 'agend_value' => 2, 'agend_value@OData.Community.Display.V1.FormattedValue' => 'Beta' ),
				),
			)
		);
		Agend_Test_WP::queue_response(
			200,
			array(
				'OptionSet' => array(
					'Options' => array(
						// Already present from the in-use query: must not be duplicated.
						array( 'Value' => 2, 'Label' => array( 'UserLocalizedLabel' => array( 'Label' => 'Beta (metadata)' ) ) ),
						// Not yet on any record: an admin can still pick it.
						array( 'Value' => 3, 'Label' => array( 'UserLocalizedLabel' => array( 'Label' => 'Gamma' ) ) ),
					),
				),
			)
		);

		$result = $this->source()->fetch_field_values( 'pca_interests' );

		$this->assertSame( 'in_use+metadata', $result['source'] );
		$this->assertSame(
			array(
				array( 'value' => '1', 'label' => 'Alpha' ),
				array( 'value' => '2', 'label' => 'Beta' ),
				array( 'value' => '3', 'label' => 'Gamma' ),
			),
			$result['options']
		);
	}

	#[Test]
	public function it_caps_options_at_the_field_values_limit_and_reports_has_more(): void {
		Agend_Test_WP::queue_response( 200, array( 'AttributeType' => 'Picklist' ) );
		Agend_Test_WP::queue_response( 200, array( 'value' => array() ) );

		$options = array();
		for ( $i = 1; $i <= 250; $i++ ) {
			$options[] = array(
				'Value' => $i,
				'Label' => array( 'UserLocalizedLabel' => array( 'Label' => sprintf( 'Option %03d', $i ) ) ),
			);
		}
		Agend_Test_WP::queue_response( 200, array( 'OptionSet' => array( 'Options' => $options ) ) );

		$result = $this->source()->fetch_field_values( 'pca_membergroup' );

		$this->assertTrue( $result['has_more'] );
		$this->assertCount( Agend_Directory_Sync_Dataverse_Source::FIELD_VALUES_LIMIT, $result['options'] );
		$this->assertSame( 'Option 001', $result['options'][0]['label'] );
		$this->assertSame( 'Option 200', $result['options'][199]['label'] );
	}

	#[Test]
	public function it_filters_non_lookup_options_by_search(): void {
		Agend_Test_WP::queue_response( 200, array( 'AttributeType' => 'State' ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array(
					array( 'agend_value' => 1, 'agend_value@OData.Community.Display.V1.FormattedValue' => 'Alpha' ),
					array( 'agend_value' => 2, 'agend_value@OData.Community.Display.V1.FormattedValue' => 'Alphabet' ),
					array( 'agend_value' => 3, 'agend_value@OData.Community.Display.V1.FormattedValue' => 'Beta' ),
				),
			)
		);
		Agend_Test_WP::queue_response( 200, array() );

		$result = $this->source()->fetch_field_values( 'statecode', 'alpha' );

		$this->assertSame(
			array(
				array( 'value' => '1', 'label' => 'Alpha' ),
				array( 'value' => '2', 'label' => 'Alphabet' ),
			),
			$result['options']
		);
	}

	#[Test]
	public function it_serves_a_second_call_from_the_cache_without_another_request(): void {
		Agend_Test_WP::queue_response( 200, array( 'AttributeType' => 'Lookup' ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array(
					array(
						'agend_value' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
						'agend_value@OData.Community.Display.V1.FormattedValue' => 'Acme Ltd',
					),
				),
			)
		);

		$source = $this->source();

		$first  = $source->fetch_field_values( 'parentcustomerid' );
		$after_first = count( Agend_Test_WP::$requests );

		$second = $source->fetch_field_values( 'parentcustomerid' );

		$this->assertSame( 2, $after_first );
		$this->assertCount( $after_first, Agend_Test_WP::$requests, 'a cached call must not issue another request' );
		$this->assertSame( $first, $second );
	}

	#[Test]
	public function refresh_forces_a_new_request_and_replaces_the_cached_result(): void {
		Agend_Test_WP::queue_response( 200, array( 'AttributeType' => 'Lookup' ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array(
					array(
						'agend_value' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
						'agend_value@OData.Community.Display.V1.FormattedValue' => 'Acme Ltd',
					),
				),
			)
		);

		$source = $this->source();
		$first  = $source->fetch_field_values( 'parentcustomerid' );

		$this->assertCount( 2, Agend_Test_WP::$requests );

		Agend_Test_WP::queue_response( 200, array( 'AttributeType' => 'Lookup' ) );
		Agend_Test_WP::queue_response(
			200,
			array(
				'value' => array(
					array(
						'agend_value' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
						'agend_value@OData.Community.Display.V1.FormattedValue' => 'Acme Ltd (renamed)',
					),
				),
			)
		);

		$second = $source->fetch_field_values( 'parentcustomerid', '', true );

		$this->assertCount( 4, Agend_Test_WP::$requests, 'refresh must issue a fresh request rather than reuse the cache' );
		$this->assertSame( 'Acme Ltd', $first['options'][0]['label'] );
		$this->assertSame( 'Acme Ltd (renamed)', $second['options'][0]['label'] );
	}
}
