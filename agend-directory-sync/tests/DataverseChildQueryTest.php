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

require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/interface-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-config.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-http-api-source.php';
require_once AGEND_TESTS_ROOT . '/agend-directory-sync/includes/class-dataverse-source.php';

/**
 * A child list query's entity set is an explicit, operator-configured
 * Dataverse EntitySetName, sanitised the same way as the main connection's
 * `entity_set` setting -- never guessed from the FetchXML's `<entity name>`
 * (SPEC-DIR-20260930-directory-item-list-field US-2.1 AC1, AC3).
 *
 * A prior heuristic guess (append "s" unless the logical name already ends
 * in "s") returned `pca_majorspecialothertenants` unchanged for the PCA
 * centre tenants entity. Its verified EntitySetName (PCA staging metadata,
 * 2026-09-30) is `pca_majorspecialothertenantses`, so the guess 404s the
 * request. `sanitize_entity_set()` being public and shared is what proves
 * this value round-trips through the same code path the main query already
 * relies on, with no guessing layer in between.
 */
#[CoversClass( Agend_Directory_Sync_Dataverse_Source::class )]
final class DataverseChildQueryTest extends TestCase {

	#[Test]
	public function it_keeps_the_verified_pca_centre_tenants_entity_set_unchanged(): void {
		$this->assertSame(
			'pca_majorspecialothertenantses',
			Agend_Directory_Sync_Dataverse_Source::sanitize_entity_set( 'pca_majorspecialothertenantses' )
		);
	}

	#[Test]
	public function it_does_not_derive_the_entity_set_from_the_logical_name(): void {
		// The logical name and the verified EntitySetName differ for this
		// entity; sanitising the logical name must not coincidentally
		// produce the correct collection name by guessing.
		$this->assertNotSame(
			'pca_majorspecialothertenantses',
			Agend_Directory_Sync_Dataverse_Source::sanitize_entity_set( 'pca_majorspecialothertenants' )
		);
	}

	#[Test]
	public function it_strips_characters_a_dataverse_entity_set_name_cannot_hold(): void {
		$this->assertSame( 'contacts', Agend_Directory_Sync_Dataverse_Source::sanitize_entity_set( ' contacts/?<> ' ) );
	}

	#[Test]
	public function it_extracts_the_entity_name_from_a_child_list_fetch_xml(): void {
		$this->assertSame(
			'pca_majorspecialothertenants',
			Agend_Directory_Sync_Dataverse_Source::extract_entity_name(
				'<fetch><entity name="pca_majorspecialothertenants"><attribute name="pca_tenantname" /></entity></fetch>'
			)
		);
	}
}
