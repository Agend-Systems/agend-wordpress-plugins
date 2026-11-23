<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\AppsCore;

use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-facets.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-map.php';

/**
 * A site whose directory shows members nothing extra can serve signed-in
 * members the same cached directory reads as everyone else, instead of a live,
 * unshared gateway call on every filter change.
 */
#[CoversFunction( 'agend_apps_directory_reads_shared' )]
#[CoversFunction( 'agend_apps_directory_facets_viewer_independent' )]
#[CoversFunction( 'agend_apps_directory_facets_read' )]
#[CoversFunction( 'agend_apps_directory_get_markers' )]
final class DirectorySharedReadsTest extends TestCase {

	private function shareReads(): void {
		update_option( \AGEND_APPS_RECORDS_DIRECTORY_SHARED_READS_OPTION, '1' );
	}

	private function signIn(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-a-token' );
	}

	/** @return string[] */
	private function storedMarkerKeys(): array {
		return array_values(
			array_filter(
				array_keys( Agend_Test_WP::$transients ),
				static fn ( string $key ): bool => 0 === strpos( $key, 'agend_apps_directory_markers' ) && '_at' !== substr( $key, -3 )
			)
		);
	}

	#[Test]
	public function should_keep_member_reads_private_when_the_setting_was_never_saved(): void {
		self::assertFalse( agend_apps_directory_reads_shared() );
	}

	#[Test]
	public function should_share_reads_when_the_setting_is_on(): void {
		$this->shareReads();

		self::assertTrue( agend_apps_directory_reads_shared() );
	}

	#[Test]
	public function should_let_a_site_decide_in_code(): void {
		$this->shareReads();
		add_filter( 'agend_apps_directory_reads_shared', static fn (): bool => false );

		self::assertFalse( agend_apps_directory_reads_shared() );
	}

	#[Test]
	public function should_treat_custom_facets_as_the_same_for_every_viewer_when_reads_are_shared(): void {
		self::assertFalse( agend_apps_directory_facets_viewer_independent( array( 'custom.profession' ) ) );
		self::assertFalse( agend_apps_directory_facets_viewer_independent( array() ) );

		$this->shareReads();

		self::assertTrue( agend_apps_directory_facets_viewer_independent( array( 'custom.profession' ) ) );
		self::assertTrue( agend_apps_directory_facets_viewer_independent( array() ) );
		self::assertTrue( agend_apps_directory_facets_read( array( 'custom.profession' ) )['args']['anonymous'] ?? false );
	}

	#[Test]
	public function should_send_a_members_map_read_with_their_token_and_store_nothing_by_default(): void {
		$this->signIn();
		Agend_Test_WP::queue_response( 200, array( 'data' => array(), 'meta' => array( 'truncated' => false ) ) );

		agend_apps_directory_get_markers( '', array( 'limit' => 500 ) );

		self::assertSame( 'Bearer member-a-token', Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null );
		self::assertSame( array(), $this->storedMarkerKeys() );
	}

	#[Test]
	public function should_serve_a_members_map_read_from_the_shared_entry_when_reads_are_shared(): void {
		$this->shareReads();
		$this->signIn();
		Agend_Test_WP::queue_response( 200, array( 'data' => array(), 'meta' => array( 'truncated' => false ) ) );

		$first  = agend_apps_directory_get_markers( '', array( 'limit' => 500 ) );
		$second = agend_apps_directory_get_markers( '', array( 'limit' => 500 ) );

		self::assertCount( 1, Agend_Test_WP::$requests, 'the second read comes from the shared entry' );
		self::assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
		self::assertCount( 1, $this->storedMarkerKeys() );
		self::assertSame( $first, $second );
	}
}
