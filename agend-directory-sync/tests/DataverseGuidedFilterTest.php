<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Dataverse_Source;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-dataverse-source.php';

/**
 * The guided secondary filter: an admin picks a field and a set of values by
 * label, and this source builds the same kind of FetchXML fragment an
 * operator would otherwise have to hand-write, so the two modes stay
 * interchangeable at the point they are composed onto the main query.
 */
#[CoversClass( Agend_Directory_Sync_Dataverse_Source::class )]
final class DataverseGuidedFilterTest extends TestCase {

	private const QUERY = '<fetch><entity name="contact"><attribute name="contactid" /></entity></fetch>';

	// -----------------------------------------------------------------
	// build_guided_filter_fragment()
	// -----------------------------------------------------------------

	#[Test]
	public function it_builds_an_in_condition_for_a_picklist_with_multiple_values(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment(
			'pca_membergroup',
			array( '798380003', '798380004' ),
			'picklist'
		);

		$this->assertStringContainsString( 'operator="in"', $xml );
		$this->assertStringContainsString( '<value>798380003</value>', $xml );
		$this->assertStringContainsString( '<value>798380004</value>', $xml );
	}

	#[Test]
	public function it_builds_an_in_condition_for_status_state_and_lookup_types(): void {
		foreach ( array( 'status', 'state', 'lookup' ) as $type ) {
			$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_field', array( '1', '2' ), $type );
			$this->assertStringContainsString( 'operator="in"', $xml, "type: $type" );
		}
	}

	#[Test]
	public function it_builds_a_single_value_in_condition(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_membergroup', array( '798380003' ), 'picklist' );

		$this->assertStringContainsString( 'operator="in"', $xml );
		$this->assertStringContainsString( '<value>798380003</value>', $xml );
	}

	#[Test]
	public function it_builds_a_contain_values_condition_for_multiselectpicklist(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment(
			'pca_interests',
			array( '1', '2', '3' ),
			'multiselectpicklist'
		);

		$this->assertStringContainsString( 'operator="contain-values"', $xml );
		$this->assertStringContainsString( '<value>1</value><value>2</value><value>3</value>', $xml );
	}

	#[Test]
	public function it_builds_an_eq_condition_for_a_declared_boolean_type(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_active', array( 'true' ), 'boolean' );

		$this->assertStringContainsString( 'operator="eq"', $xml );
		$this->assertStringContainsString( 'value="true"', $xml );
	}

	#[Test]
	public function it_builds_an_eq_condition_for_a_single_boolean_value_with_no_declared_type(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_active', array( 'false' ), '' );

		$this->assertStringContainsString( 'operator="eq"', $xml );
		$this->assertStringContainsString( 'value="false"', $xml );
	}

	#[Test]
	public function it_uses_the_first_value_for_an_eq_condition(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_active', array( 'true' ), 'boolean' );

		$this->assertStringNotContainsString( '<value>', $xml );
	}

	#[Test]
	public function it_accepts_a_guid_value(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment(
			'parentcustomerid',
			array( '3fa85f64-5717-4562-b3fc-2c963f66afa6' ),
			'lookup'
		);

		$this->assertStringContainsString( '<value>3fa85f64-5717-4562-b3fc-2c963f66afa6</value>', $xml );
	}

	#[Test]
	public function it_returns_blank_for_a_blank_field(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( '', array( '1' ), 'picklist' ) );
	}

	#[Test]
	public function it_returns_blank_for_no_values(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_membergroup', array(), 'picklist' ) );
	}

	#[Test]
	public function it_returns_blank_when_every_value_is_invalid(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_membergroup', array( 'not a value' ), 'picklist' ) );
	}

	#[Test]
	public function the_built_fragment_is_a_valid_secondary_filter(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_membergroup', array( '1', '2' ), 'picklist' );

		$this->assertTrue( Agend_Directory_Sync_Dataverse_Source::is_valid_secondary_filter( $xml ) );
	}

	#[Test]
	public function the_built_fragment_survives_injection_and_page_building(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_membergroup', array( '1', '2' ), 'picklist' );

		$composed = Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, $xml );
		$paged    = Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml( $composed, 1, 50, '' );

		$this->assertStringContainsString( 'pca_membergroup', $paged );
		$this->assertStringContainsString( 'page="1"', $paged );
	}

	/**
	 * The values sanitiser only ever admits an integer, a GUID, or
	 * true/false onto a built fragment, none of which contain XML specials,
	 * so this exercises the writer the same way `build_guided_filter_fragment()`
	 * does: elements built with `DOMDocument::createElement()` and rendered
	 * with `saveXML()`, proving a value is never string-spliced into the
	 * document even though no accepted value shape needs escaping in
	 * practice.
	 */
	#[Test]
	public function values_are_written_through_dom_elements_not_string_splicing(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_guided_filter_fragment( 'pca_membergroup', array( '1', '2' ), 'picklist' );

		$doc = new \DOMDocument();
		$this->assertTrue( $doc->loadXML( $xml ) !== false );
		$this->assertSame( 'condition', $doc->documentElement->nodeName );
	}

	// -----------------------------------------------------------------
	// resolve_secondary_filter_fragment()
	// -----------------------------------------------------------------

	#[Test]
	public function it_resolves_the_raw_fragment_in_raw_mode(): void {
		$settings = array(
			'secondary_filter_mode' => Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_MODE_RAW,
			'secondary_filter'      => '<condition attribute="a" operator="eq" value="1" />',
		);

		$this->assertSame(
			'<condition attribute="a" operator="eq" value="1" />',
			Agend_Directory_Sync_Dataverse_Source::resolve_secondary_filter_fragment( $settings )
		);
	}

	#[Test]
	public function it_resolves_the_built_fragment_in_guided_mode(): void {
		$settings = array(
			'secondary_filter_mode'        => Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_MODE_GUIDED,
			'secondary_filter_field'       => 'pca_membergroup',
			'secondary_filter_field_type'  => 'picklist',
			'secondary_filter_values'      => array( array( 'value' => '1', 'label' => 'North' ) ),
			'secondary_filter'             => 'ignored in guided mode',
		);

		$fragment = Agend_Directory_Sync_Dataverse_Source::resolve_secondary_filter_fragment( $settings );

		$this->assertStringContainsString( 'pca_membergroup', $fragment );
		$this->assertStringContainsString( '<value>1</value>', $fragment );
	}

	// -----------------------------------------------------------------
	// describe_secondary_filter()
	// -----------------------------------------------------------------

	#[Test]
	public function it_describes_the_guided_filter_in_plain_language(): void {
		$settings = array(
			'secondary_filter_mode'   => Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_MODE_GUIDED,
			'secondary_filter_field'  => 'pca_membergroup',
			'secondary_filter_values' => array(
				array( 'value' => '1', 'label' => 'Region North' ),
				array( 'value' => '2', 'label' => 'Region South' ),
			),
		);

		$this->assertSame(
			'pca_membergroup limited to Region North, Region South',
			Agend_Directory_Sync_Dataverse_Source::describe_secondary_filter( $settings )
		);
	}

	#[Test]
	public function it_describes_nothing_in_raw_mode(): void {
		$settings = array(
			'secondary_filter_mode'   => Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_MODE_RAW,
			'secondary_filter_field'  => 'pca_membergroup',
			'secondary_filter_values' => array( array( 'value' => '1', 'label' => 'North' ) ),
		);

		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::describe_secondary_filter( $settings ) );
	}

	#[Test]
	public function it_describes_nothing_when_the_guided_filter_is_unconfigured(): void {
		$settings = array(
			'secondary_filter_mode'   => Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_MODE_GUIDED,
			'secondary_filter_field'  => '',
			'secondary_filter_values' => array(),
		);

		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::describe_secondary_filter( $settings ) );
	}

	// -----------------------------------------------------------------
	// extract_entity_name()
	// -----------------------------------------------------------------

	#[Test]
	public function it_extracts_the_entity_name(): void {
		$this->assertSame( 'contact', Agend_Directory_Sync_Dataverse_Source::extract_entity_name( self::QUERY ) );
	}

	#[Test]
	public function it_fails_to_extract_the_entity_name_from_unparsable_xml(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::extract_entity_name( '<fetch><entity name="contact">' );
	}

	#[Test]
	public function it_fails_to_extract_the_entity_name_when_there_is_no_entity(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no <entity>' );

		Agend_Directory_Sync_Dataverse_Source::extract_entity_name( '<fetch />' );
	}

	#[Test]
	public function it_fails_to_extract_the_entity_name_when_the_entity_has_no_name(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no "name" attribute' );

		Agend_Directory_Sync_Dataverse_Source::extract_entity_name( '<fetch><entity /></fetch>' );
	}

	// -----------------------------------------------------------------
	// sanitize_secondary_filter_mode()
	// -----------------------------------------------------------------

	#[Test]
	public function it_accepts_the_guided_mode_exactly(): void {
		$this->assertSame( 'guided', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_mode( 'guided' ) );
		$this->assertSame( 'guided', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_mode( '  guided  ' ) );
	}

	#[Test]
	public function it_defaults_an_unrecognised_mode_to_raw(): void {
		$this->assertSame( 'raw', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_mode( 'GUIDED' ) );
		$this->assertSame( 'raw', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_mode( 'something-else' ) );
		$this->assertSame( 'raw', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_mode( '' ) );
	}

	// -----------------------------------------------------------------
	// sanitize_secondary_filter_field()
	// -----------------------------------------------------------------

	#[Test]
	public function it_accepts_a_valid_field_name_and_lowercases_it(): void {
		$this->assertSame( 'pca_membergroup', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field( ' PCA_MemberGroup ' ) );
		$this->assertSame( '_leading_underscore', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field( '_leading_underscore' ) );
	}

	#[Test]
	public function it_rejects_an_invalid_field_name(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field( '1starts_with_digit' ) );
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field( 'has space' ) );
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field( 'has.dot' ) );
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field( '' ) );
	}

	// -----------------------------------------------------------------
	// sanitize_secondary_filter_field_type()
	// -----------------------------------------------------------------

	#[Test]
	public function it_accepts_every_known_field_type(): void {
		foreach ( array( 'picklist', 'multiselectpicklist', 'boolean', 'status', 'state', 'lookup', 'customer', 'owner' ) as $type ) {
			$this->assertSame( $type, Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field_type( strtoupper( $type ) ) );
		}
	}

	#[Test]
	public function it_rejects_an_unknown_field_type(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field_type( 'string' ) );
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_field_type( '' ) );
	}

	// -----------------------------------------------------------------
	// sanitize_secondary_filter_values()
	// -----------------------------------------------------------------

	#[Test]
	public function it_accepts_scalar_values(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values( array( '798380003', '798380004' ) );

		$this->assertSame(
			array(
				array( 'value' => '798380003', 'label' => '798380003' ),
				array( 'value' => '798380004', 'label' => '798380004' ),
			),
			$values
		);
	}

	#[Test]
	public function it_accepts_value_label_rows(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values(
			array( array( 'value' => '1', 'label' => 'Region North' ) )
		);

		$this->assertSame( array( array( 'value' => '1', 'label' => 'Region North' ) ), $values );
	}

	#[Test]
	public function it_applies_a_json_label_map_to_scalar_values(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values(
			array( '1', '2' ),
			wp_json_encode( array( '1' => 'Region North', '2' => 'Region South' ) )
		);

		$this->assertSame(
			array(
				array( 'value' => '1', 'label' => 'Region North' ),
				array( 'value' => '2', 'label' => 'Region South' ),
			),
			$values
		);
	}

	#[Test]
	public function a_malformed_label_map_is_ignored_rather_than_fatal(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values( array( '1' ), '{not json' );

		$this->assertSame( array( array( 'value' => '1', 'label' => '1' ) ), $values );
	}

	#[Test]
	public function it_accepts_an_integer_value_optionally_signed(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values( array( '-5', '+7', '0' ) );

		$this->assertSame( array( '-5', '+7', '0' ), array_column( $values, 'value' ) );
	}

	#[Test]
	public function it_accepts_a_guid_and_normalises_it(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values(
			array( '{3FA85F64-5717-4562-B3FC-2C963F66AFA6}' )
		);

		$this->assertSame( array( '3fa85f64-5717-4562-b3fc-2c963f66afa6' ), array_column( $values, 'value' ) );
	}

	#[Test]
	public function it_accepts_true_and_false_case_insensitively(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values( array( 'TRUE', 'False' ) );

		$this->assertSame( array( 'true', 'false' ), array_column( $values, 'value' ) );
	}

	#[Test]
	public function it_drops_a_value_that_matches_no_accepted_shape(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values( array( 'not-a-value', '', '1.5' ) );

		$this->assertSame( array(), $values );
	}

	#[Test]
	public function it_collapses_duplicate_values_keeping_the_first_label(): void {
		$values = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values(
			array(
				array( 'value' => '1', 'label' => 'First' ),
				array( 'value' => '1', 'label' => 'Second' ),
			)
		);

		$this->assertCount( 1, $values );
		$this->assertSame( 'First', $values[0]['label'] );
	}

	#[Test]
	public function it_returns_an_empty_list_for_a_non_array_input(): void {
		$this->assertSame( array(), Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values( 'not an array' ) );
		$this->assertSame( array(), Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_values( null ) );
	}

	// -----------------------------------------------------------------
	// build_secondary_filter_from_args()
	// -----------------------------------------------------------------

	#[Test]
	public function it_builds_from_a_valid_raw_flag(): void {
		$fragment = Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args(
			array( 'secondary-filter' => '<condition attribute="a" operator="eq" value="1" />' )
		);

		$this->assertSame( '<condition attribute="a" operator="eq" value="1" />', $fragment );
	}

	#[Test]
	public function it_returns_blank_for_an_explicitly_empty_raw_flag(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args( array( 'secondary-filter' => '' ) ) );
	}

	#[Test]
	public function it_rejects_an_invalid_raw_flag(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args( array( 'secondary-filter' => '<not xml' ) );
	}

	#[Test]
	public function it_rejects_mixing_raw_and_guided_flags(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args(
			array(
				'secondary-filter'       => '',
				'secondary-filter-field' => 'pca_membergroup',
			)
		);
	}

	#[Test]
	public function it_rejects_a_field_without_values(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args( array( 'secondary-filter-field' => 'pca_membergroup' ) );
	}

	#[Test]
	public function it_rejects_values_without_a_field(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args( array( 'secondary-filter-values' => '1,2' ) );
	}

	#[Test]
	public function it_rejects_an_invalid_field_name_from_args(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args(
			array(
				'secondary-filter-field'  => 'has space',
				'secondary-filter-values' => '1',
			)
		);
	}

	#[Test]
	public function it_rejects_values_that_all_sanitise_away(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args(
			array(
				'secondary-filter-field'  => 'pca_membergroup',
				'secondary-filter-values' => 'not-a-value, also not one',
			)
		);
	}

	#[Test]
	public function it_builds_from_valid_guided_args(): void {
		$fragment = Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args(
			array(
				'secondary-filter-field'  => 'pca_membergroup',
				'secondary-filter-values' => '798380003, 798380004',
			)
		);

		$this->assertStringContainsString( 'pca_membergroup', $fragment );
		$this->assertStringContainsString( '<value>798380003</value>', $fragment );
		$this->assertStringContainsString( '<value>798380004</value>', $fragment );
	}

	#[Test]
	public function it_returns_blank_when_none_of_the_flags_are_present(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::build_secondary_filter_from_args( array() ) );
	}

	// -----------------------------------------------------------------
	// The two save paths: the filter's own save, and the settings save
	// that no longer posts it.
	// -----------------------------------------------------------------

	#[Test]
	public function it_should_return_only_the_secondary_filter_keys_from_a_posted_array(): void {
		$input = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_input(
			array(
				'environment_url'             => 'https://evil.example.com',
				'fetch_xml'                   => '<fetch />',
				'secondary_filter_mode'       => 'guided',
				'secondary_filter_field'      => 'pca_membergroup',
				'secondary_filter_field_type' => 'picklist',
				'secondary_filter_values'     => array( '798380003' ),
				'secondary_filter_value_labels' => '{"798380003":"Region North"}',
			)
		);

		$this->assertSame(
			array(
				'secondary_filter',
				'secondary_filter_mode',
				'secondary_filter_field',
				'secondary_filter_field_type',
				'secondary_filter_values',
			),
			array_keys( $input ),
			'a filter save must never carry a connection setting with it'
		);
		$this->assertArrayNotHasKey( 'environment_url', $input );
		$this->assertArrayNotHasKey( 'fetch_xml', $input );
	}

	#[Test]
	public function it_should_sanitise_every_key_it_returns(): void {
		$input = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_input(
			array(
				'secondary_filter_mode'       => 'nonsense',
				'secondary_filter_field'      => 'Not A Field',
				'secondary_filter_field_type' => 'invented',
				'secondary_filter_values'     => array( 'not-a-value', '798380003' ),
				'secondary_filter'            => '<filter><condition attribute="a"',
			)
		);

		$this->assertSame( Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_MODE_RAW, $input['secondary_filter_mode'] );
		$this->assertSame( '', $input['secondary_filter_field'] );
		$this->assertSame( '', $input['secondary_filter_field_type'] );
		$this->assertSame( array( array( 'value' => '798380003', 'label' => '798380003' ) ), $input['secondary_filter_values'] );
		$this->assertSame( '', $input['secondary_filter'], 'an unparseable fragment is dropped rather than stored' );
	}

	#[Test]
	public function it_should_apply_the_posted_label_map_when_saving_the_filter_alone(): void {
		$input = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_input(
			array(
				'secondary_filter_values'       => array( '798380003' ),
				'secondary_filter_value_labels' => '{"798380003":"Region North"}',
			)
		);

		$this->assertSame( array( array( 'value' => '798380003', 'label' => 'Region North' ) ), $input['secondary_filter_values'] );
	}

	#[Test]
	public function it_should_return_defaults_for_a_non_array_filter_input(): void {
		foreach ( array( null, 'string', 7 ) as $bad ) {
			$input = Agend_Directory_Sync_Dataverse_Source::sanitize_secondary_filter_input( $bad );

			$this->assertSame( '', $input['secondary_filter'] );
			$this->assertSame( Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_MODE_RAW, $input['secondary_filter_mode'] );
			$this->assertSame( array(), $input['secondary_filter_values'] );
		}
	}

	#[Test]
	public function it_should_carry_a_saved_filter_through_a_settings_save_that_omits_it(): void {
		$saved = array(
			'environment_url'             => 'https://example.crm6.dynamics.com',
			'secondary_filter_mode'       => 'guided',
			'secondary_filter_field'      => 'pca_membergroup',
			'secondary_filter_field_type' => 'picklist',
			'secondary_filter_values'     => array( array( 'value' => '798380003', 'label' => 'Region North' ) ),
			'secondary_filter'            => '',
		);

		$carried = Agend_Directory_Sync_Dataverse_Source::carry_secondary_filter(
			array( 'environment_url' => 'https://example.crm6.dynamics.com', 'page_size' => '250' ),
			$saved
		);

		$this->assertSame( 'guided', $carried['secondary_filter_mode'] );
		$this->assertSame( 'pca_membergroup', $carried['secondary_filter_field'] );
		$this->assertSame( 'picklist', $carried['secondary_filter_field_type'] );
		$this->assertSame( $saved['secondary_filter_values'], $carried['secondary_filter_values'] );
		$this->assertSame( '250', $carried['page_size'], 'an unrelated posted setting passes through untouched' );
	}

	#[Test]
	public function it_should_treat_a_posted_but_empty_filter_as_a_deliberate_clear(): void {
		$carried = Agend_Directory_Sync_Dataverse_Source::carry_secondary_filter(
			array(
				'secondary_filter_mode'   => 'guided',
				'secondary_filter_field'  => '',
				'secondary_filter_values' => array(),
			),
			array(
				'secondary_filter_mode'   => 'guided',
				'secondary_filter_field'  => 'pca_membergroup',
				'secondary_filter_values' => array( array( 'value' => '798380003', 'label' => 'Region North' ) ),
			)
		);

		$this->assertSame( '', $carried['secondary_filter_field'], 'clearing the field on purpose must not be undone' );
		$this->assertSame( array(), $carried['secondary_filter_values'] );
	}

	#[Test]
	public function it_should_not_invent_filter_keys_a_saved_option_never_had(): void {
		$carried = Agend_Directory_Sync_Dataverse_Source::carry_secondary_filter(
			array( 'environment_url' => 'https://example.crm6.dynamics.com' ),
			array( 'environment_url' => 'https://example.crm6.dynamics.com' )
		);

		$this->assertSame( array( 'environment_url' ), array_keys( $carried ) );
	}
}
