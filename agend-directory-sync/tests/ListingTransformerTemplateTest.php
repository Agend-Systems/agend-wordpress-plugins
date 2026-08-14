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
 * Concatenation-template sources flowing through the listing transformer
 * (SPEC-DIR-20260731 US-3.2): core fields, custom fields, and location
 * sub-fields all resolve a `{path}` template the same way a plain source
 * field resolves a dot-path.
 */
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class ListingTransformerTemplateTest extends TestCase {

	private function fieldMap( array $overrides = array() ): array {
		$core = array_merge(
			array(
				'external_id' => 'id',
				'description' => '{name_first} {name_last}',
			),
			$overrides['core'] ?? array()
		);

		$locations = array(
			array_merge(
				array( 'label' => 'Main' ),
				$overrides['locations'][0] ?? array(),
			),
		);

		return array(
			'core'          => $core,
			'custom_fields' => $overrides['custom_fields'] ?? array(),
			'locations'     => $locations,
		);
	}

	#[Test]
	public function it_resolves_a_core_field_template_into_the_payload(): void {
		$contacts = array(
			array(
				'id'         => '1',
				'name_first' => 'John',
				'name_last'  => 'Smith',
			),
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $this->fieldMap() );

		$this->assertSame( 'John Smith', $result['listings'][0]['description'] );
	}

	#[Test]
	public function it_resolves_a_custom_field_template_into_the_payload(): void {
		$contacts = array(
			array(
				'id'     => '1',
				'unit'   => '4',
				'street' => 'Main St',
			),
		);

		$field_map = $this->fieldMap(
			array(
				'custom_fields' => array( 'street_address' => '{unit}/{street}' ),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $field_map );

		$this->assertSame( '4/Main St', $result['listings'][0]['custom_fields']['street_address'] );
	}

	#[Test]
	public function it_resolves_a_location_sub_field_template(): void {
		$contacts = array(
			array(
				'id'     => '1',
				'unit'   => '4',
				'street' => 'Main St',
			),
		);

		$field_map = $this->fieldMap(
			array(
				'locations' => array(
					array( 'address_line_1' => '{unit}/{street}' ),
				),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $field_map );

		$this->assertSame( '4/Main St', $result['listings'][0]['locations'][0]['address_line_1'] );
	}

	#[Test]
	public function it_omits_a_core_field_whose_template_resolves_entirely_empty(): void {
		$contacts = array(
			array( 'id' => '1' ),
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $this->fieldMap() );

		$this->assertArrayNotHasKey( 'description', $result['listings'][0] );
	}

	#[Test]
	public function it_still_resolves_a_plain_single_field_mapping(): void {
		$contacts = array(
			array(
				'id'    => '1',
				'email' => 'john@example.test',
			),
		);

		$field_map = $this->fieldMap( array( 'core' => array( 'email' => 'email' ) ) );

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $field_map );

		$this->assertSame( 'john@example.test', $result['listings'][0]['email'] );
	}

	/**
	 * OData-style APIs (e.g. Dynamics) annotate a field with a literal key
	 * such as `pca_state@OData.Community.Display.V1.FormattedValue`,
	 * carrying the human-readable label alongside the raw coded value
	 * (`pca_state`). The mapped source is the annotated key; the resolver's
	 * exact top-level key match resolves it directly (no dot-path
	 * traversal), so the formatted value — not the numeric code — lands in
	 * the payload.
	 */
	#[Test]
	public function it_resolves_an_odata_annotated_key_as_a_plain_source(): void {
		$contacts = array(
			array(
				'id'        => '1',
				'pca_state@OData.Community.Display.V1.FormattedValue' => 'QLD',
				'pca_state' => 798380003,
			),
		);

		$field_map = $this->fieldMap(
			array(
				'core' => array( 'description' => 'pca_state@OData.Community.Display.V1.FormattedValue' ),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $field_map );

		$this->assertSame( 'QLD', $result['listings'][0]['description'] );
	}

	#[Test]
	public function it_resolves_an_odata_annotated_key_as_a_template_placeholder(): void {
		$contacts = array(
			array(
				'id'        => '1',
				'pca_state@OData.Community.Display.V1.FormattedValue' => 'QLD',
				'pca_state' => 798380003,
			),
		);

		$field_map = $this->fieldMap(
			array(
				'core' => array( 'description' => '{pca_state@OData.Community.Display.V1.FormattedValue}' ),
			)
		);

		$result = Agend_Directory_Sync_Listing_Transformer::transform_all( $contacts, $field_map );

		$this->assertSame( 'QLD', $result['listings'][0]['description'] );
	}
}
