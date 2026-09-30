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
 * The shared paginator's entity-set guess for a child list query
 * (SPEC-DIR-20260930-directory-item-list-field US-2.1 AC3), and that the
 * refactor extracting `fetch_all()`'s loop into `paginate_query()` did not
 * change the paging contract already proven by DataverseFetchXmlTest.
 */
#[CoversClass( Agend_Directory_Sync_Dataverse_Source::class )]
final class DataverseChildQueryTest extends TestCase {

	#[Test]
	public function it_leaves_an_already_plural_entity_name_unchanged(): void {
		$this->assertSame(
			'pca_majorspecialothertenants',
			Agend_Directory_Sync_Dataverse_Source::guess_entity_set( 'pca_majorspecialothertenants' )
		);
	}

	#[Test]
	public function it_appends_an_s_to_a_singular_entity_name(): void {
		$this->assertSame( 'contacts', Agend_Directory_Sync_Dataverse_Source::guess_entity_set( 'contact' ) );
	}

	#[Test]
	public function it_lowercases_the_entity_name(): void {
		$this->assertSame( 'accounts', Agend_Directory_Sync_Dataverse_Source::guess_entity_set( 'Account' ) );
	}

	#[Test]
	public function it_returns_empty_for_a_blank_entity_name(): void {
		$this->assertSame( '', Agend_Directory_Sync_Dataverse_Source::guess_entity_set( '' ) );
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
