<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Path_Resolver;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';

/**
 * Concatenation-template support in the path resolver (SPEC-DIR-20260731 US-3.2).
 */
#[CoversClass( Agend_Directory_Sync_Path_Resolver::class )]
final class PathResolverTemplateTest extends TestCase {

	#[Test]
	public function it_concatenates_two_fields_with_a_literal_separator(): void {
		$data = array(
			'a' => 'John',
			'b' => 'Smith',
		);

		$this->assertSame( 'John Smith', Agend_Directory_Sync_Path_Resolver::resolve_template( $data, '{a} {b}' ) );
	}

	#[Test]
	public function it_resolves_nested_paths_inside_placeholders(): void {
		$data = array(
			'contact' => array(
				'first' => 'Jane',
			),
		);

		$this->assertSame( 'Jane', Agend_Directory_Sync_Path_Resolver::resolve_template( $data, '{contact.first}' ) );
	}

	#[Test]
	public function it_keeps_literal_separators_between_placeholders(): void {
		$data = array(
			'unit'   => '4',
			'street' => 'Main St',
		);

		$this->assertSame( '4/Main St', Agend_Directory_Sync_Path_Resolver::resolve_template( $data, '{unit}/{street}' ) );
	}

	#[Test]
	public function it_collapses_whitespace_when_one_placeholder_is_missing(): void {
		$data = array(
			'a' => 'John',
		);

		// $b resolves to '', leaving "John " before collapsing/trimming.
		$this->assertSame( 'John', Agend_Directory_Sync_Path_Resolver::resolve_template( $data, '{a} {b}' ) );
	}

	#[Test]
	public function it_returns_empty_string_when_every_placeholder_is_blank(): void {
		$data = array();

		$this->assertSame( '', Agend_Directory_Sync_Path_Resolver::resolve_template( $data, 'Member: {a}, {b}' ) );
	}

	#[Test]
	public function it_leaves_a_non_template_value_untouched_by_is_template(): void {
		$this->assertFalse( Agend_Directory_Sync_Path_Resolver::is_template( 'addresses.0.suburb' ) );
		$this->assertTrue( Agend_Directory_Sync_Path_Resolver::is_template( '{a} {b}' ) );
	}

	#[Test]
	public function it_treats_an_array_shaped_placeholder_value_as_blank(): void {
		$data = array(
			'a' => array( 'x', 'y' ),
			'b' => 'Smith',
		);

		$this->assertSame( 'Smith', Agend_Directory_Sync_Path_Resolver::resolve_template( $data, '{a} {b}' ) );
	}

	#[Test]
	public function it_resolves_an_odata_annotated_key_placeholder(): void {
		$data = array(
			'pca_state@OData.Community.Display.V1.FormattedValue' => 'QLD',
			'pca_state' => 798380003,
		);

		$this->assertSame(
			'QLD',
			Agend_Directory_Sync_Path_Resolver::resolve_template( $data, '{pca_state@OData.Community.Display.V1.FormattedValue}' )
		);
	}
}
