<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\DirectorySync;

use Agend_Directory_Sync_Field_Map;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-path-resolver.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-field-map.php';

/**
 * `sanitize_source_path()` (exercised via the public sanitize_core /
 * sanitize_custom_fields entry points) preserves concatenation templates
 * while keeping the strict plain-path behaviour unchanged (SPEC-DIR-20260731
 * US-3.2).
 */
#[CoversClass( Agend_Directory_Sync_Field_Map::class )]
final class FieldMapSanitizeTest extends TestCase {

	#[Test]
	public function it_preserves_a_template_with_spaces_and_a_slash_separator(): void {
		$core = Agend_Directory_Sync_Field_Map::sanitize_core(
			array( 'description' => '{name_first} {name_last}/{suffix}' )
		);

		$this->assertSame( '{name_first} {name_last}/{suffix}', $core['description'] );
	}

	#[Test]
	public function it_strips_exotic_characters_from_a_template(): void {
		$core = Agend_Directory_Sync_Field_Map::sanitize_core(
			array( 'description' => '{a}<script>alert(1)</script>{b}' )
		);

		$this->assertStringNotContainsString( '<', $core['description'] );
		$this->assertStringNotContainsString( '>', $core['description'] );
	}

	#[Test]
	public function it_leaves_a_non_template_value_behaving_exactly_as_before(): void {
		// Unchanged legacy behaviour: the strict plain-path charset strips
		// spaces and angle brackets exactly as it always has, with no notion
		// of "words" to preserve — this is the pre-existing sanitizer, not a
		// bug introduced by template support.
		$core = Agend_Directory_Sync_Field_Map::sanitize_core(
			array( 'description' => 'addresses.0.suburb <bad>' )
		);

		$this->assertSame( 'addresses.0.suburbbad', $core['description'] );
	}

	#[Test]
	public function it_preserves_a_template_in_the_custom_fields_map(): void {
		$custom_fields = Agend_Directory_Sync_Field_Map::sanitize_custom_fields(
			"street_address = {unit}/{street}\n"
		);

		$this->assertSame( '{unit}/{street}', $custom_fields['street_address'] );
	}

	#[Test]
	public function it_preserves_an_odata_annotated_key_as_a_plain_source(): void {
		// OData-style APIs (e.g. Dynamics) annotate a field with a literal
		// key containing '@', e.g.
		// pca_state@OData.Community.Display.V1.FormattedValue. The '@' must
		// survive sanitisation or the saved mapping never resolves.
		$core = Agend_Directory_Sync_Field_Map::sanitize_core(
			array( 'description' => 'pca_state@OData.Community.Display.V1.FormattedValue' )
		);

		$this->assertSame( 'pca_state@OData.Community.Display.V1.FormattedValue', $core['description'] );
	}

	#[Test]
	public function it_preserves_an_odata_annotated_key_inside_a_template(): void {
		$core = Agend_Directory_Sync_Field_Map::sanitize_core(
			array( 'description' => '{pca_state@OData.Community.Display.V1.FormattedValue}' )
		);

		$this->assertSame( '{pca_state@OData.Community.Display.V1.FormattedValue}', $core['description'] );
	}
}
