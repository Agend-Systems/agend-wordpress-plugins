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
 * Yes/no custom fields flowing through the listing transformer. A source
 * boolean (Dataverse returns JSON booleans) is sent as a native bool so a
 * false value is kept: a string cast would turn false into '' and drop it,
 * and because a sync replaces the listing's whole custom_fields bag, the key
 * would be deleted on every run. Every other empty value is still omitted.
 */
#[CoversClass( Agend_Directory_Sync_Listing_Transformer::class )]
final class ListingTransformerBoolCustomFieldsTest extends TestCase {

	private function fieldMap( array $custom_fields ): array {
		return array(
			'core'          => array( 'external_id' => 'id' ),
			'custom_fields' => $custom_fields,
			'locations'     => array( array( 'label' => 'Main' ) ),
		);
	}

	private function customFields( array $contact, array $custom_fields ): array {
		$result = Agend_Directory_Sync_Listing_Transformer::transform_all(
			array( $contact ),
			$this->fieldMap( $custom_fields )
		);

		return $result['listings'][0]['custom_fields'] ?? array();
	}

	#[Test]
	public function should_send_a_false_source_value_as_false(): void {
		$custom_fields = $this->customFields(
			array(
				'id'     => '1',
				'accred' => false,
			),
			array( 'accredited' => 'accred' )
		);

		$this->assertArrayHasKey( 'accredited', $custom_fields );
		$this->assertSame( false, $custom_fields['accredited'] );
	}

	#[Test]
	public function should_send_a_true_source_value_as_true_rather_than_one(): void {
		$custom_fields = $this->customFields(
			array(
				'id'     => '1',
				'accred' => true,
			),
			array( 'accredited' => 'accred' )
		);

		$this->assertArrayHasKey( 'accredited', $custom_fields );
		$this->assertSame( true, $custom_fields['accredited'] );
	}

	#[Test]
	public function should_omit_a_null_source_value(): void {
		$custom_fields = $this->customFields(
			array(
				'id'     => '1',
				'accred' => null,
			),
			array( 'accredited' => 'accred' )
		);

		$this->assertArrayNotHasKey( 'accredited', $custom_fields );
	}

	#[Test]
	public function should_omit_an_empty_string_source_value(): void {
		$custom_fields = $this->customFields(
			array(
				'id'     => '1',
				'accred' => '',
			),
			array( 'accredited' => 'accred' )
		);

		$this->assertArrayNotHasKey( 'accredited', $custom_fields );
	}

	#[Test]
	public function should_omit_a_source_field_missing_from_the_row(): void {
		$custom_fields = $this->customFields(
			array( 'id' => '1' ),
			array( 'accredited' => 'accred' )
		);

		$this->assertArrayNotHasKey( 'accredited', $custom_fields );
	}

	#[Test]
	public function should_send_a_source_string_false_unchanged_as_a_string(): void {
		$custom_fields = $this->customFields(
			array(
				'id'     => '1',
				'accred' => 'false',
			),
			array( 'accredited' => 'accred' )
		);

		$this->assertSame( 'false', $custom_fields['accredited'] );
	}

	#[Test]
	public function should_keep_custom_fields_on_a_listing_whose_only_custom_field_is_false(): void {
		$result = Agend_Directory_Sync_Listing_Transformer::transform_all(
			array(
				array(
					'id'     => '1',
					'accred' => false,
				),
			),
			$this->fieldMap( array( 'accredited' => 'accred' ) )
		);

		$this->assertArrayHasKey( 'custom_fields', $result['listings'][0] );
		$this->assertSame( array( 'accredited' => false ), $result['listings'][0]['custom_fields'] );
	}

	#[Test]
	public function should_keep_true_and_false_fields_alongside_string_fields(): void {
		$custom_fields = $this->customFields(
			array(
				'id'     => '1',
				'accred' => false,
				'member' => true,
				'region' => 'North',
				'blank'  => '  ',
			),
			array(
				'accredited' => 'accred',
				'is_member'  => 'member',
				'region'     => 'region',
				'blank'      => 'blank',
			)
		);

		$this->assertSame(
			array(
				'accredited' => false,
				'is_member'  => true,
				'region'     => 'North',
			),
			$custom_fields
		);
	}

	/**
	 * A `{template}` source always resolves to a string, so a bool placeholder
	 * is not kept as a bool: a false value renders as blank and the field is
	 * omitted, a true value renders as "1". This is unchanged by the native
	 * bool handling, which only applies to a plain (non-template) source.
	 */
	#[Test]
	public function should_omit_a_template_source_over_a_false_value(): void {
		$custom_fields = $this->customFields(
			array(
				'id'   => '1',
				'flag' => false,
			),
			array( 'accredited' => '{flag}' )
		);

		$this->assertArrayNotHasKey( 'accredited', $custom_fields );
	}

	#[Test]
	public function should_render_a_template_source_over_a_true_value_as_the_string_one(): void {
		$custom_fields = $this->customFields(
			array(
				'id'   => '1',
				'flag' => true,
			),
			array( 'accredited' => '{flag}' )
		);

		$this->assertSame( '1', $custom_fields['accredited'] );
	}
}
