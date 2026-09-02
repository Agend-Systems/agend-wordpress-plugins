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
