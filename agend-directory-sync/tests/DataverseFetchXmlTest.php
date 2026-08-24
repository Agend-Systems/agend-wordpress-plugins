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
 * The FetchXML paging contract: the page window the plugin asks for is the one
 * that reaches Dataverse, and the paging cookie survives the round trip in the
 * form the server issued it.
 */
#[CoversClass( Agend_Directory_Sync_Dataverse_Source::class )]
final class DataverseFetchXmlTest extends TestCase {

	private const QUERY = '<fetch><entity name="contact"><attribute name="contactid" /><order attribute="contactid" /></entity></fetch>';

	#[Test]
	public function it_should_set_the_page_and_count_attributes_when_building_a_page(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml( self::QUERY, 3, 250, '' );

		$this->assertStringContainsString( 'page="3"', $xml );
		$this->assertStringContainsString( 'count="250"', $xml );
	}

	#[Test]
	public function it_should_overwrite_page_and_count_already_present_in_the_query(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml(
			'<fetch page="7" count="10"><entity name="contact" /></fetch>',
			1,
			500,
			''
		);

		$this->assertStringContainsString( 'page="1"', $xml );
		$this->assertStringContainsString( 'count="500"', $xml );
		$this->assertStringNotContainsString( 'page="7"', $xml );
		$this->assertStringNotContainsString( 'count="10"', $xml );
	}

	#[Test]
	public function it_should_keep_the_selected_attributes_and_order_of_the_query(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml( self::QUERY, 1, 500, '' );

		$this->assertStringContainsString( '<attribute name="contactid"/>', str_replace( ' />', '/>', $xml ) );
		$this->assertStringContainsString( 'entity name="contact"', $xml );
		$this->assertStringContainsString( 'order attribute="contactid"', $xml );
	}

	#[Test]
	public function it_should_omit_the_paging_cookie_attribute_on_a_cold_page(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml( self::QUERY, 1, 500, '' );

		$this->assertStringNotContainsString( 'paging-cookie', $xml );
	}

	#[Test]
	public function it_should_drop_a_stale_paging_cookie_left_in_the_query(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml(
			'<fetch paging-cookie="&lt;cookie page=&quot;9&quot;/&gt;"><entity name="contact" /></fetch>',
			1,
			500,
			''
		);

		$this->assertStringNotContainsString( 'paging-cookie', $xml );
	}

	/**
	 * The cookie travels as an attribute value, so the XML writer escapes it
	 * and the server unescapes it back to what it issued. Asserting on the
	 * re-parsed value rather than the serialised text is the point: it is the
	 * round trip that has to be lossless.
	 */
	#[Test]
	public function it_should_carry_the_paging_cookie_through_escaping_unchanged(): void {
		$cookie = '<cookie page="1"><contactid last="{7A1B}" first="{2C3D}" /></cookie>';

		$xml = Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml( self::QUERY, 2, 500, $cookie );

		$this->assertStringNotContainsString( '<cookie', $xml, 'the cookie must be escaped, not embedded as live markup' );

		$parsed = simplexml_load_string( $xml );
		$this->assertNotFalse( $parsed );
		$this->assertSame( $cookie, (string) $parsed['paging-cookie'] );
	}

	#[Test]
	public function it_should_url_decode_the_paging_cookie_annotation(): void {
		$decoded = Agend_Directory_Sync_Dataverse_Source::extract_paging_cookie(
			array(
				Agend_Directory_Sync_Dataverse_Source::ANNOTATION_PAGING_COOKIE => '%3Ccookie+page%3D%221%22%2F%3E',
			)
		);

		$this->assertSame( '<cookie page="1"/>', $decoded );
	}

	#[Test]
	public function it_should_return_no_cookie_when_the_response_carries_no_annotation(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::extract_paging_cookie( array( 'value' => array() ) ) );
	}

	#[Test]
	public function it_should_reject_a_query_that_is_not_valid_xml(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml( '<fetch><entity name="contact">', 1, 500, '' );
	}

	#[Test]
	public function it_should_reject_a_query_whose_root_element_is_not_fetch(): void {
		$this->expectException( RuntimeException::class );

		Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml( '<query><entity name="contact" /></query>', 1, 500, '' );
	}

	#[Test]
	public function it_should_stop_paging_when_the_more_records_annotation_is_false(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Dataverse_Source::has_more_records(
				array( Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS => false ),
				500,
				500
			)
		);
	}

	#[Test]
	public function it_should_continue_paging_when_the_more_records_annotation_is_true(): void {
		$this->assertTrue(
			Agend_Directory_Sync_Dataverse_Source::has_more_records(
				array( Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS => true ),
				10,
				500
			)
		);
	}

	#[Test]
	public function it_should_read_a_string_more_records_annotation_as_a_boolean(): void {
		$this->assertTrue(
			Agend_Directory_Sync_Dataverse_Source::has_more_records(
				array( Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS => 'true' ),
				10,
				500
			)
		);
		$this->assertFalse(
			Agend_Directory_Sync_Dataverse_Source::has_more_records(
				array( Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS => 'false' ),
				10,
				500
			)
		);
	}

	#[Test]
	public function it_should_fall_back_to_the_short_page_test_when_the_annotation_is_absent(): void {
		$this->assertTrue( Agend_Directory_Sync_Dataverse_Source::has_more_records( array(), 500, 500 ) );
		$this->assertFalse( Agend_Directory_Sync_Dataverse_Source::has_more_records( array(), 499, 500 ) );
	}

	#[Test]
	public function it_should_stop_paging_on_an_empty_page_whatever_the_annotation_says(): void {
		$this->assertFalse(
			Agend_Directory_Sync_Dataverse_Source::has_more_records(
				array( Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS => true ),
				0,
				500
			)
		);
	}

	#[Test]
	public function it_should_build_the_web_api_url_with_the_query_url_encoded(): void {
		$url = Agend_Directory_Sync_Dataverse_Source::build_request_url(
			'https://org.crm6.dynamics.com/',
			'9.2',
			'contacts',
			'<fetch><entity name="contact" /></fetch>'
		);

		$this->assertStringStartsWith( 'https://org.crm6.dynamics.com/api/data/v9.2/contacts?fetchXml=', $url );
		$this->assertStringNotContainsString( '<', $url );
		$this->assertStringContainsString( '%3Cfetch%3E', $url );
	}

	#[Test]
	public function it_should_derive_the_token_url_from_the_tenant_when_not_overridden(): void {
		$this->assertSame(
			'https://login.microsoftonline.com/tenant-guid/oauth2/v2.0/token',
			Agend_Directory_Sync_Dataverse_Source::resolve_token_url( '', 'tenant-guid' )
		);
	}

	#[Test]
	public function it_should_prefer_an_explicit_token_url_over_the_tenant(): void {
		$this->assertSame(
			'https://login.microsoftonline.us/t/oauth2/v2.0/token',
			Agend_Directory_Sync_Dataverse_Source::resolve_token_url( 'https://login.microsoftonline.us/t/oauth2/v2.0/token', 'tenant-guid' )
		);
	}

	#[Test]
	public function it_should_derive_the_scope_from_the_environment_url(): void {
		$this->assertSame(
			'https://org.crm6.dynamics.com/.default',
			Agend_Directory_Sync_Dataverse_Source::resolve_scope( '', 'https://org.crm6.dynamics.com/' )
		);
	}

	#[Test]
	public function it_should_prefer_an_explicit_scope_over_the_environment_url(): void {
		$this->assertSame(
			'https://other.example/.default',
			Agend_Directory_Sync_Dataverse_Source::resolve_scope( 'https://other.example/.default', 'https://org.crm6.dynamics.com' )
		);
	}

	/**
	 * The paging cookie and the more-records flag ARE annotations, so a request
	 * that suppresses annotations entirely cannot be paged from.
	 */
	#[Test]
	public function it_should_always_request_the_paging_annotations(): void {
		$narrow = Agend_Directory_Sync_Dataverse_Source::build_odata_headers( false );

		$this->assertStringContainsString( Agend_Directory_Sync_Dataverse_Source::ANNOTATION_MORE_RECORDS, $narrow['Prefer'] );
		$this->assertStringContainsString( Agend_Directory_Sync_Dataverse_Source::ANNOTATION_PAGING_COOKIE, $narrow['Prefer'] );

		$wide = Agend_Directory_Sync_Dataverse_Source::build_odata_headers( true );

		$this->assertSame( 'odata.include-annotations="*"', $wide['Prefer'] );
		$this->assertSame( '4.0', $wide['OData-Version'] );
	}
}
