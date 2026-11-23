<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Key_Scopes;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;
use WP_REST_Request;

require_once __DIR__ . '/fixtures/resources-fixtures.php';

/**
 * The surface gate rows for the three Resources surfaces: the scope each needs,
 * what a key that lacks it gets, and the routes the gate closes with it.
 *
 * Kept beside the Resources tests rather than in SurfaceGateTest, so the rows
 * of the surfaces built side by side never meet in one file.
 */
final class ResourcesSurfaceGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		agend_res_test_reset();
	}

	/** @return array<string, array{string, string[]}> */
	public static function requirements(): array {
		return array(
			'collections' => array( 'resources-collections', array( 'atlas.collections.browse' ) ),
			'search'      => array( 'resources-search', array( 'atlas.pages.search' ) ),
			'ask'         => array( 'resources-ask', array( 'atlas.questions.create' ) ),
		);
	}

	/**
	 * @param string[] $scopes Scopes the surface needs.
	 */
	#[Test]
	#[DataProvider( 'requirements' )]
	public function should_hold_each_surface_to_exactly_its_scope( string $surface, array $scopes ): void {
		self::assertSame( $scopes, agend_apps_records_surface_requirements( $surface )['scopes'] );
		self::assertSame( array( 'atlas' ), agend_apps_records_surface_requirements( $surface )['apps'] );

		agend_res_test_know( array( 'crm.contacts.browse' ) );
		self::assertSame( $scopes, agend_apps_records_surface_missing( $surface )['scopes'] );
		self::assertTrue( agend_apps_records_surface_gated( $surface ) );

		agend_res_test_know( $scopes );
		self::assertTrue( agend_apps_records_surface_available( $surface ) );
	}

	#[Test]
	#[DataProvider( 'requirements' )]
	public function should_honour_the_wildcards_and_leave_a_surface_open_when_the_scopes_are_unknown( string $surface ): void {
		self::assertTrue( agend_apps_records_surface_available( $surface ), 'unknown: the gateway stays the authority' );

		agend_res_test_know( array( '*' ) );
		self::assertTrue( agend_apps_records_surface_available( $surface ) );

		agend_res_test_know( array( 'atlas.*' ) );
		self::assertTrue( agend_apps_records_surface_available( $surface ) );

		agend_res_test_know( array( 'lms.*' ) );
		self::assertFalse( agend_apps_records_surface_available( $surface ) );
	}

	#[Test]
	#[DataProvider( 'requirements' )]
	public function should_close_a_surface_whose_app_the_key_is_restricted_away_from( string $surface, array $scopes ): void {
		agend_res_test_know( $scopes, array() );
		Agend_Apps_Key_Scopes::store_from_response( array( 'data' => array( 'scopes' => $scopes, 'app_ids' => array( 'crm' ) ) ) );

		self::assertSame( 'atlas', agend_apps_records_surface_missing( $surface )['app'] );
		self::assertStringContainsString( '"atlas" app', agend_apps_records_surface_notice( $surface ) );
	}

	#[Test]
	#[DataProvider( 'requirements' )]
	public function should_name_the_scope_and_the_widget_in_the_notice( string $surface, array $scopes ): void {
		agend_res_test_know( array( 'crm.contacts.browse' ) );

		$notice = agend_apps_records_surface_notice( $surface );

		self::assertStringContainsString( $scopes[0], $notice );
		self::assertStringContainsString( 'Agend Resources', $notice );
		self::assertStringContainsString( 'Test connection', $notice );
	}

	/** @return array<string, array{string, string}> */
	public static function routes(): array {
		return array(
			'my collections' => array( '/agend-apps/v1/resources/collections/mine', 'resources-collections' ),
			'search'         => array( '/agend-apps/v1/resources/search', 'resources-search' ),
			'ask'            => array( '/agend-apps/v1/resources/ask', 'resources-ask' ),
			'elementor ns'   => array( '/agend-elementor/v1/resources/search', 'resources-search' ),
			'trailing slash' => array( '/agend-apps/v1/resources/ask/', 'resources-ask' ),
		);
	}

	#[Test]
	#[DataProvider( 'routes' )]
	public function should_map_each_route_to_the_surface_that_owns_it( string $route, string $surface ): void {
		self::assertSame( $surface, agend_apps_records_rest_route_surface( $route ) );
	}

	#[Test]
	public function should_map_nothing_else_under_resources(): void {
		foreach ( array( '/agend-apps/v1/resources', '/agend-apps/v1/resources/other', '/agend-apps/v1/resources/search/extra', '/agend-apps/v1/resources/ask/extra', '/agend-apps/v1/other/resources/ask' ) as $route ) {
			self::assertSame( '', agend_apps_records_rest_route_surface( $route ), $route );
		}
	}

	#[Test]
	#[DataProvider( 'routes' )]
	public function should_refuse_a_route_whose_surface_is_gated_with_the_notice( string $route, string $surface ): void {
		agend_res_test_know( array( 'crm.contacts.browse' ) );

		$result = agend_apps_records_surface_gate_rest( null, null, new WP_REST_Request( 'GET', $route ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'agend_apps_feature_unavailable', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
		self::assertSame( $surface, $result->get_error_data()['surface'] );
	}

	#[Test]
	#[DataProvider( 'routes' )]
	public function should_let_a_route_through_when_the_key_holds_the_scope( string $route, string $surface ): void {
		agend_res_test_know( agend_apps_records_surface_requirements( $surface )['scopes'] );

		self::assertNull( agend_apps_records_surface_gate_rest( null, null, new WP_REST_Request( 'GET', $route ) ) );
	}

	#[Test]
	public function should_gate_each_surface_on_its_own_scope_only(): void {
		agend_res_test_know( array( 'atlas.pages.search' ) );

		self::assertTrue( agend_apps_records_surface_available( 'resources-search' ) );
		self::assertFalse( agend_apps_records_surface_available( 'resources-collections' ) );
		self::assertFalse( agend_apps_records_surface_available( 'resources-ask' ) );
	}

	#[Test]
	public function should_ship_the_three_surfaces_in_the_block_list_with_a_built_block_each(): void {
		foreach ( array_keys( self::requirements() ) as $key ) {
			$surface = self::requirements()[ $key ][0];

			self::assertContains( $surface, AGEND_APPS_RECORDS_BLOCK_SURFACES, $surface );
			self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/' . $surface . '/block.json', $surface );
			self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/' . $surface . '/render.php', $surface );
			self::assertFileExists( AGEND_TESTS_ROOT . '/agend-apps-core/build/blocks/' . $surface . '/index.asset.php', $surface );
		}
	}
}
