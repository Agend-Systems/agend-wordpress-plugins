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
 * The secondary filter contract: a separately configured FetchXML filter is
 * composed onto the saved query at fetch time, narrowing the result without
 * the saved query itself changing, so one sync run can target one group.
 */
#[CoversClass( Agend_Directory_Sync_Dataverse_Source::class )]
final class DataverseSecondaryFilterTest extends TestCase {

	private const QUERY = '<fetch><entity name="contact"><attribute name="contactid" /><filter type="and"><condition attribute="statecode" operator="eq" value="0" /></filter><order attribute="contactid" /></entity></fetch>';

	private const GROUP = '<filter type="and"><condition attribute="pca_membergroup" operator="eq" value="Region North" /></filter>';

	#[Test]
	public function it_should_add_the_filter_as_another_filter_under_the_entity(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, self::GROUP );

		$doc = new \DOMDocument();
		$doc->loadXML( $xml );
		$entity  = $doc->documentElement->getElementsByTagName( 'entity' )->item( 0 );
		$filters = array();
		foreach ( $entity->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'filter' === $child->nodeName ) {
				$filters[] = $child;
			}
		}

		$this->assertCount( 2, $filters, 'the main query filter and the secondary filter should be siblings under <entity>' );
		$this->assertSame( 'statecode', $filters[0]->getElementsByTagName( 'condition' )->item( 0 )->getAttribute( 'attribute' ) );
		$this->assertSame( 'pca_membergroup', $filters[1]->getElementsByTagName( 'condition' )->item( 0 )->getAttribute( 'attribute' ) );
		$this->assertSame( 'Region North', $filters[1]->getElementsByTagName( 'condition' )->item( 0 )->getAttribute( 'value' ) );
	}

	#[Test]
	public function it_should_leave_the_main_query_filter_and_attributes_untouched(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, self::GROUP );

		$this->assertStringContainsString( '<condition attribute="statecode" operator="eq" value="0"/>', str_replace( ' />', '/>', $xml ) );
		$this->assertStringContainsString( '<attribute name="contactid"/>', str_replace( ' />', '/>', $xml ) );
		$this->assertStringContainsString( '<order attribute="contactid"/>', str_replace( ' />', '/>', $xml ) );
	}

	#[Test]
	public function it_should_return_the_query_unchanged_when_there_is_no_secondary_filter(): void {
		$this->assertSame( self::QUERY, Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, '' ) );
		$this->assertSame( self::QUERY, Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, "  \n " ) );
	}

	#[Test]
	public function it_should_wrap_a_bare_condition_in_an_and_filter(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter(
			self::QUERY,
			'<condition attribute="pca_membergroup" operator="eq" value="Region North" />'
		);

		$this->assertStringContainsString(
			'<filter type="and"><condition attribute="pca_membergroup" operator="eq" value="Region North"/></filter>',
			str_replace( ' />', '/>', $xml )
		);
	}

	#[Test]
	public function it_should_carry_a_multi_condition_filter_through_whole(): void {
		$fragment = '<filter type="or"><condition attribute="pca_membergroup" operator="eq" value="A" /><condition attribute="pca_membergroup" operator="in"><value>B</value><value>C</value></condition></filter>';

		$xml = Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, $fragment );

		$this->assertStringContainsString( '<filter type="or">', $xml );
		$this->assertStringContainsString( '<value>B</value><value>C</value>', $xml );
	}

	#[Test]
	public function it_should_survive_page_building_on_the_composed_query(): void {
		$composed = Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, self::GROUP );
		$paged    = Agend_Directory_Sync_Dataverse_Source::build_page_fetch_xml( $composed, 2, 100, '' );

		$this->assertStringContainsString( 'page="2"', $paged );
		$this->assertStringContainsString( 'count="100"', $paged );
		$this->assertStringContainsString( 'pca_membergroup', $paged );
		$this->assertStringContainsString( 'statecode', $paged );
	}

	#[Test]
	public function it_should_escape_a_value_containing_xml_specials_through_the_writer(): void {
		$xml = Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter(
			self::QUERY,
			'<condition attribute="fullname" operator="like" value="Smith &amp; Sons%" />'
		);

		$this->assertStringContainsString( 'value="Smith &amp; Sons%"', $xml );
	}

	#[Test]
	public function it_should_reject_a_fragment_that_is_not_valid_xml(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'secondary filter is not valid XML' );

		Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, '<filter><condition attribute="a"' );
	}

	#[Test]
	public function it_should_reject_a_fragment_whose_root_is_not_a_filter_or_condition(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'found <attribute>' );

		Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( self::QUERY, '<attribute name="fullname" />' );
	}

	#[Test]
	public function it_should_reject_a_query_with_no_entity_to_attach_to(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no <entity>' );

		Agend_Directory_Sync_Dataverse_Source::inject_secondary_filter( '<fetch />', self::GROUP );
	}

	#[Test]
	public function it_should_validate_a_fragment_the_same_way_the_run_path_parses_it(): void {
		$this->assertTrue( Agend_Directory_Sync_Dataverse_Source::is_valid_secondary_filter( self::GROUP ) );
		$this->assertTrue( Agend_Directory_Sync_Dataverse_Source::is_valid_secondary_filter( '<condition attribute="a" operator="null" />' ) );
		$this->assertFalse( Agend_Directory_Sync_Dataverse_Source::is_valid_secondary_filter( '' ) );
		$this->assertFalse( Agend_Directory_Sync_Dataverse_Source::is_valid_secondary_filter( '<entity name="contact" />' ) );
		$this->assertFalse( Agend_Directory_Sync_Dataverse_Source::is_valid_secondary_filter( 'not xml' ) );
	}

	#[Test]
	public function it_should_use_the_saved_fragment_when_nothing_overrides_it(): void {
		$this->assertSame( self::GROUP, Agend_Directory_Sync_Dataverse_Source::effective_secondary_filter( self::GROUP ) );
	}

	#[Test]
	public function it_should_let_a_run_scoped_override_replace_the_saved_fragment(): void {
		$override = '<condition attribute="pca_membergroup" operator="eq" value="Region South" />';

		add_filter(
			Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_HOOK,
			static function ( string $saved ) use ( $override ): string {
				return $override;
			}
		);

		$this->assertSame( $override, Agend_Directory_Sync_Dataverse_Source::effective_secondary_filter( self::GROUP ) );
	}

	#[Test]
	public function it_should_let_a_run_scoped_override_clear_the_saved_fragment(): void {
		add_filter(
			Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_HOOK,
			static function (): string {
				return '';
			}
		);

		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::effective_secondary_filter( self::GROUP ) );
	}

	#[Test]
	public function it_should_treat_a_non_string_override_as_no_filter(): void {
		add_filter(
			Agend_Directory_Sync_Dataverse_Source::SECONDARY_FILTER_HOOK,
			static function () {
				return null;
			}
		);

		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::effective_secondary_filter( self::GROUP ) );
	}
}
