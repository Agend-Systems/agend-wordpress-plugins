<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync;
use Agend_Directory_Sync_Dataverse_Source;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// The main plugin file is required for the option-key constants; its
// bootstrap only registers hooks on `plugins_loaded`, which never fires here.
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/agend-directory-sync.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-dataverse-source.php';

/**
 * Save-time sanitisation of the Dataverse connection settings: what gets
 * stored, what gets clamped, and what gets refused rather than persisted in a
 * state that would fail every run.
 */
#[CoversClass( Agend_Directory_Sync_Dataverse_Source::class )]
final class DataverseSettingsTest extends TestCase {

	private const QUERY = '<fetch><entity name="contact"><attribute name="contactid" /></entity></fetch>';

	/**
	 * @param array<string, mixed> $raw
	 *
	 * @return array<string, mixed>
	 */
	private function sanitized( array $raw ): array {
		return Agend_Directory_Sync_Dataverse_Source::sanitize_settings( $raw );
	}

	#[Test]
	public function it_should_store_a_valid_fetch_xml_query_verbatim(): void {
		$settings = $this->sanitized( array( 'fetch_xml' => self::QUERY ) );

		$this->assertSame( self::QUERY, $settings['fetch_xml'] );
	}

	#[Test]
	public function it_should_drop_a_fetch_xml_query_that_will_not_parse(): void {
		$settings = $this->sanitized( array( 'fetch_xml' => '<fetch><entity name="contact">' ) );

		$this->assertSame( '', $settings['fetch_xml'] );
	}

	#[Test]
	public function it_should_drop_a_fetch_xml_query_whose_root_is_not_fetch(): void {
		$settings = $this->sanitized( array( 'fetch_xml' => '<select><entity name="contact" /></select>' ) );

		$this->assertSame( '', $settings['fetch_xml'] );
	}

	/**
	 * A query holding connection variables is still a template at save time, so
	 * the braces must not cost it validation.
	 */
	#[Test]
	public function it_should_keep_a_fetch_xml_query_containing_connection_variables(): void {
		$query = '<fetch><entity name="contact"><filter><condition attribute="parentcustomerid" operator="eq" value="{org_id}" /></filter></entity></fetch>';

		$settings = $this->sanitized( array( 'fetch_xml' => $query ) );

		$this->assertSame( $query, $settings['fetch_xml'] );
	}

	#[Test]
	public function it_should_store_a_valid_secondary_filter_verbatim(): void {
		$fragment = '<filter type="and"><condition attribute="pca_membergroup" operator="eq" value="Region North" /></filter>';

		$settings = $this->sanitized( array( 'fetch_xml' => self::QUERY, 'secondary_filter' => "  $fragment\n" ) );

		$this->assertSame( $fragment, $settings['secondary_filter'] );
	}

	#[Test]
	public function it_should_store_a_bare_condition_as_the_secondary_filter(): void {
		$fragment = '<condition attribute="pca_membergroup" operator="eq" value="Region North" />';

		$settings = $this->sanitized( array( 'secondary_filter' => $fragment ) );

		$this->assertSame( $fragment, $settings['secondary_filter'] );
	}

	#[Test]
	public function it_should_drop_a_secondary_filter_that_will_not_parse(): void {
		$settings = $this->sanitized( array( 'secondary_filter' => '<filter><condition attribute="a"' ) );

		$this->assertSame( '', $settings['secondary_filter'] );
	}

	#[Test]
	public function it_should_drop_a_secondary_filter_whose_root_is_not_a_filter(): void {
		$settings = $this->sanitized( array( 'secondary_filter' => '<fetch><entity name="contact" /></fetch>' ) );

		$this->assertSame( '', $settings['secondary_filter'] );
	}

	#[Test]
	public function it_should_keep_a_secondary_filter_containing_connection_variables(): void {
		$fragment = '<condition attribute="pca_membergroup" operator="eq" value="{group}" />';

		$settings = $this->sanitized( array( 'secondary_filter' => $fragment ) );

		$this->assertSame( $fragment, $settings['secondary_filter'] );
	}

	#[Test]
	public function it_should_default_the_secondary_filter_blank_for_an_option_saved_before_it_existed(): void {
		$settings = $this->sanitized( array( 'fetch_xml' => self::QUERY ) );

		$this->assertSame( '', $settings['secondary_filter'] );
	}

	#[Test]
	public function it_should_drop_an_environment_url_that_is_not_https(): void {
		$settings = $this->sanitized( array( 'environment_url' => 'ftp://org.crm6.dynamics.com' ) );

		$this->assertSame( '', $settings['environment_url'] );
	}

	#[Test]
	public function it_should_strip_a_trailing_slash_from_the_environment_url(): void {
		$settings = $this->sanitized( array( 'environment_url' => 'https://org.crm6.dynamics.com/' ) );

		$this->assertSame( 'https://org.crm6.dynamics.com', $settings['environment_url'] );
	}

	/**
	 * The entity set goes into the URL path, so anything outside the character
	 * set a Dataverse entity-set name can hold is removed rather than escaped.
	 */
	#[Test]
	public function it_should_reduce_the_entity_set_to_name_characters(): void {
		$settings = $this->sanitized( array( 'entity_set' => ' contacts?$select=name ' ) );

		$this->assertSame( 'contactsselectname', $settings['entity_set'] );
	}

	#[Test]
	public function it_should_clamp_the_page_size_to_the_dataverse_maximum(): void {
		$this->assertSame(
			Agend_Directory_Sync_Dataverse_Source::MAX_PAGE_SIZE,
			$this->sanitized( array( 'page_size' => 99999 ) )['page_size']
		);
		$this->assertSame(
			Agend_Directory_Sync_Dataverse_Source::MIN_PAGE_SIZE,
			$this->sanitized( array( 'page_size' => 0 ) )['page_size']
		);
	}

	#[Test]
	public function it_should_default_a_non_numeric_page_size(): void {
		$this->assertSame(
			Agend_Directory_Sync_Dataverse_Source::DEFAULT_PAGE_SIZE,
			$this->sanitized( array( 'page_size' => 'lots' ) )['page_size']
		);
	}

	#[Test]
	public function it_should_treat_page_one_as_the_lowest_start_page(): void {
		$this->assertSame( 1, $this->sanitized( array( 'start_page' => 0 ) )['start_page'] );
		$this->assertSame( 4, $this->sanitized( array( 'start_page' => 4 ) )['start_page'] );
	}

	#[Test]
	public function it_should_clamp_max_pages_to_the_hard_safety_cap(): void {
		$this->assertSame(
			Agend_Directory_Sync_Dataverse_Source::MAX_PAGES,
			$this->sanitized( array( 'max_pages' => 100000 ) )['max_pages']
		);
		$this->assertSame( 0, $this->sanitized( array() )['max_pages'] );
	}

	#[Test]
	public function it_should_default_a_blank_api_version(): void {
		$this->assertSame(
			Agend_Directory_Sync_Dataverse_Source::DEFAULT_API_VERSION,
			$this->sanitized( array( 'api_version' => '' ) )['api_version']
		);
		$this->assertSame( '9.1', $this->sanitized( array( 'api_version' => 'v9.1' ) )['api_version'] );
	}

	#[Test]
	public function it_should_read_an_unchecked_paging_cookie_box_as_off(): void {
		$this->assertFalse( $this->sanitized( array() )['use_paging_cookie'] );
		$this->assertTrue( $this->sanitized( array( 'use_paging_cookie' => '1' ) )['use_paging_cookie'] );
	}

	/**
	 * The client secret has its own storage path; a value posted into the
	 * settings array must not be persisted alongside the rest of the connection.
	 */
	#[Test]
	public function it_should_never_persist_a_client_secret_in_the_settings_array(): void {
		$settings = $this->sanitized(
			array(
				'client_secret' => 'super-secret',
				'client_id'     => 'app-guid',
			)
		);

		$this->assertArrayNotHasKey( 'client_secret', $settings );
		$this->assertSame( 'app-guid', $settings['client_id'] );
	}

	/**
	 * An install that saved these settings before the paging-cookie and
	 * annotation toggles existed has no stored value for them; reading the
	 * option must not silently turn paging cookies off.
	 */
	// -----------------------------------------------------------------
	// Guided secondary filter settings
	// -----------------------------------------------------------------

	#[Test]
	public function it_should_coerce_the_secondary_filter_mode(): void {
		$this->assertSame( 'guided', $this->sanitized( array( 'secondary_filter_mode' => 'guided' ) )['secondary_filter_mode'] );
		$this->assertSame( 'raw', $this->sanitized( array( 'secondary_filter_mode' => 'bogus' ) )['secondary_filter_mode'] );
		$this->assertSame( 'raw', $this->sanitized( array() )['secondary_filter_mode'] );
	}

	#[Test]
	public function it_should_accept_a_valid_secondary_filter_field_name(): void {
		$this->assertSame( 'pca_membergroup', $this->sanitized( array( 'secondary_filter_field' => ' PCA_MemberGroup ' ) )['secondary_filter_field'] );
	}

	#[Test]
	public function it_should_reject_an_invalid_secondary_filter_field_name(): void {
		$this->assertSame( '', $this->sanitized( array( 'secondary_filter_field' => '1bad name' ) )['secondary_filter_field'] );
	}

	#[Test]
	public function it_should_whitelist_the_secondary_filter_field_type(): void {
		$this->assertSame( 'picklist', $this->sanitized( array( 'secondary_filter_field_type' => 'PICKLIST' ) )['secondary_filter_field_type'] );
		$this->assertSame( '', $this->sanitized( array( 'secondary_filter_field_type' => 'string' ) )['secondary_filter_field_type'] );
	}

	#[Test]
	public function it_should_accept_secondary_filter_values_as_scalars(): void {
		$settings = $this->sanitized( array( 'secondary_filter_values' => array( '1', '2' ) ) );

		$this->assertSame(
			array(
				array( 'value' => '1', 'label' => '1' ),
				array( 'value' => '2', 'label' => '2' ),
			),
			$settings['secondary_filter_values']
		);
	}

	#[Test]
	public function it_should_accept_secondary_filter_values_as_value_label_rows(): void {
		$settings = $this->sanitized(
			array(
				'secondary_filter_values' => array( array( 'value' => '1', 'label' => 'Region North' ) ),
			)
		);

		$this->assertSame( array( array( 'value' => '1', 'label' => 'Region North' ) ), $settings['secondary_filter_values'] );
	}

	#[Test]
	public function it_should_apply_the_json_label_map_to_secondary_filter_values(): void {
		$settings = $this->sanitized(
			array(
				'secondary_filter_values'        => array( '1' ),
				'secondary_filter_value_labels'  => wp_json_encode( array( '1' => 'Region North' ) ),
			)
		);

		$this->assertSame( array( array( 'value' => '1', 'label' => 'Region North' ) ), $settings['secondary_filter_values'] );
	}

	#[Test]
	public function it_should_drop_invalid_secondary_filter_values(): void {
		$settings = $this->sanitized( array( 'secondary_filter_values' => array( 'not-a-value', '1' ) ) );

		$this->assertSame( array( array( 'value' => '1', 'label' => '1' ) ), $settings['secondary_filter_values'] );
	}

	#[Test]
	public function it_should_default_the_guided_filter_settings_blank_for_an_option_saved_before_they_existed(): void {
		$settings = $this->sanitized( array( 'fetch_xml' => self::QUERY ) );

		$this->assertSame( 'raw', $settings['secondary_filter_mode'] );
		$this->assertSame( '', $settings['secondary_filter_field'] );
		$this->assertSame( '', $settings['secondary_filter_field_type'] );
		$this->assertSame( array(), $settings['secondary_filter_values'] );
	}

	/**
	 * resolve_settings() re-sanitises every guided filter setting from the
	 * stored option, exactly like it already re-clamps the numeric
	 * settings, so an option written by any other path (a direct
	 * update_option() call, a stale migration) can never produce a fragment
	 * this source would refuse to build.
	 */
	#[Test]
	public function it_should_re_sanitise_a_hostile_stored_guided_filter_option(): void {
		update_option(
			Agend_Directory_Sync::OPTION_DATAVERSE,
			array(
				'environment_url'             => 'https://org.crm6.dynamics.com',
				'entity_set'                  => 'contacts',
				'secondary_filter_mode'       => 'guided',
				'secondary_filter_field'      => 'Has Space; DROP TABLE',
				'secondary_filter_field_type' => 'not-a-real-type',
				'secondary_filter_values'     => array( 'not-a-value', '1', array( 'value' => '2', 'label' => "<script>alert('x')</script>" ) ),
			)
		);

		$settings = Agend_Directory_Sync_Dataverse_Source::resolve_settings();

		$this->assertSame( 'guided', $settings['secondary_filter_mode'] );
		$this->assertSame( '', $settings['secondary_filter_field'], 'the field contains characters no logical name can hold, so it is dropped rather than passed through' );
		$this->assertSame( '', $settings['secondary_filter_field_type'] );
		$this->assertSame(
			array(
				array( 'value' => '1', 'label' => '1' ),
				array( 'value' => '2', 'label' => 'alert(\'x\')' ),
			),
			$settings['secondary_filter_values']
		);
	}

	#[Test]
	public function it_should_default_the_toggles_on_for_an_option_saved_before_they_existed(): void {
		update_option(
			Agend_Directory_Sync::OPTION_DATAVERSE,
			array(
				'environment_url' => 'https://org.crm6.dynamics.com',
				'entity_set'      => 'contacts',
			)
		);

		$settings = Agend_Directory_Sync_Dataverse_Source::resolve_settings();

		$this->assertTrue( $settings['use_paging_cookie'] );
		$this->assertTrue( $settings['include_annotations'] );
		$this->assertSame( Agend_Directory_Sync_Dataverse_Source::DEFAULT_PAGE_SIZE, $settings['page_size'] );
	}
}
