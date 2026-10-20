<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Cache;
use Agend_Apps_Records_Filter_Context;
use Agend_Apps_Template_Renderer;
use Agend_Apps_Templates;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-renderer.php';

/** Renders template 150 as a filter template holding the filter widgets in $filters. */
final class Catalogue_Preload_Filter_Template implements Agend_Apps_Template_Renderer {

	/** @var array<int, array<string, mixed>> Filter widget settings, in page order. */
	public static array $filters = array();

	public function is_valid_template( int $template_id ): bool {
		return 150 === $template_id;
	}

	public function ensure_styles( int $template_id ): void {}

	public function render( int $template_id, string $type, array $record, array $extra = array(), bool $with_css = false ): string {
		return '';
	}

	public function render_plain( int $template_id, bool $with_css = false ): string {
		return '<aside>' . implode( '', array_map( 'agend_apps_records_render_filter', self::$filters ) ) . '</aside>';
	}
}

/**
 * A directory catalogue's first HTML carries the shared facets and categories
 * entries its filter template needs when they are already cached, so the
 * filters work at first paint, and the render never calls the gateway for
 * them.
 *
 * Each case runs in its own process: the render doubles define the gateway
 * list functions, and other tests in this suite assert those do NOT exist.
 */
#[RunTestsInSeparateProcesses]
final class CatalogueFilterPreloadTest extends TestCase {

	private const FETCHED_AT = 1760000000;

	private const CITY     = array( 'filter' => 'listing:city', 'control' => 'select' );
	private const TAGS     = array( 'filter' => 'listing:tag', 'control' => 'buttons' );
	private const CATEGORY = array( 'filter' => 'listing:category', 'control' => 'select' );
	private const SIZE     = array( 'filter' => 'listing:custom_field', 'custom_field_key' => 'size', 'control' => 'select' );

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-categories.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-facets.php';
		agend_render_test_reset();
		Agend_Apps_Templates::register_renderer( new Catalogue_Preload_Filter_Template() );
		Agend_Apps_Records_Filter_Context::reset();
		Catalogue_Preload_Filter_Template::$filters = array( self::CITY, self::TAGS, self::CITY, self::CATEGORY );
	}

	#[Test]
	public function should_inline_the_shared_facets_entry_when_it_is_warm(): void {
		$body = $this->warmFacets( array( 'location.city', 'tags' ) );

		$preload = $this->renderedConfig()['preload'] ?? array();

		$this->assertSame( array( 'location.city', 'tags' ), $preload['facets']['names'] ?? null );
		$this->assertSame( $body, $preload['facets']['body'] ?? null );
		$this->assertSame( self::FETCHED_AT, $preload['facets']['fetched_at'] ?? null );
		$this->assertSame( \Agend_Apps_Settings::get_cache_ttl( 'directory_facets' ), $preload['facets']['ttl'] ?? null );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_inline_nothing_and_call_no_gateway_when_the_entries_are_cold(): void {
		$config = $this->renderedConfig();

		$this->assertArrayNotHasKey( 'preload', $config );
		$this->assertSame( array(), Agend_Test_WP::$requests );
	}

	#[Test]
	public function should_inline_nothing_when_an_entry_has_no_recorded_fetch_time(): void {
		$this->warmFacets( array( 'location.city', 'tags' ) );
		unset( Agend_Test_WP::$transients[ $this->facetsTransient( array( 'location.city', 'tags' ) ) . '_at' ] );

		$this->assertArrayNotHasKey( 'preload', $this->renderedConfig() );
	}

	#[Test]
	public function should_inline_the_shared_categories_entry_when_it_is_warm(): void {
		$body = $this->warmCategories( 500 );

		$preload = $this->renderedConfig()['preload'] ?? array();

		$this->assertSame( $body, $preload['categories']['body'] ?? null );
		$this->assertSame( self::FETCHED_AT, $preload['categories']['fetched_at'] ?? null );
		$this->assertSame( \Agend_Apps_Settings::get_cache_ttl( 'directory_categories' ), $preload['categories']['ttl'] ?? null );
	}

	#[Test]
	public function should_inline_the_list_an_older_gateway_capped_when_only_that_is_cached(): void {
		$body = $this->warmCategories( 100 );

		$this->assertSame( $body, $this->renderedConfig()['preload']['categories']['body'] ?? null );
	}

	#[Test]
	public function should_inline_no_categories_when_no_filter_reads_them(): void {
		Catalogue_Preload_Filter_Template::$filters = array( self::CITY );
		$this->warmCategories( 500 );

		$this->assertArrayNotHasKey( 'preload', $this->renderedConfig() );
	}

	#[Test]
	public function should_derive_the_facet_names_the_browser_derives_from_the_rendered_shells(): void {
		Catalogue_Preload_Filter_Template::$filters = array( self::SIZE, self::TAGS, self::CATEGORY, self::CITY, self::SIZE, self::TAGS );

		$html = agend_apps_records_render_directory_catalogue( array( 'card_template' => '7', 'filter_template' => '150' ) );

		$browser = array();
		preg_match_all( '/data-agend-filter="([^"]*)"/', $html, $matches );
		foreach ( $matches[1] as $attribute ) {
			$facet = json_decode( html_entity_decode( $attribute, ENT_QUOTES ), true )['source']['facet'] ?? '';
			if ( '' !== $facet && ! in_array( $facet, $browser, true ) ) {
				$browser[] = $facet;
			}
		}

		$this->assertSame( array( 'custom.size', 'tags', 'location.city' ), $browser );
		$this->assertSame( $browser, agend_apps_records_filter_facet_names( $this->renderedConfigs() ) );
	}

	#[Test]
	public function should_inline_the_shared_facets_for_a_signed_in_visitor_when_no_facet_is_a_custom_field(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
		$this->warmFacets( array( 'location.city', 'tags' ) );
		$this->warmCategories( 500 );

		$preload = $this->renderedConfig()['preload'] ?? array();

		$this->assertSame( array( 'location.city', 'tags' ), $preload['facets']['names'] ?? null );
		$this->assertArrayHasKey( 'categories', $preload );
	}

	#[Test]
	public function should_not_inline_the_shared_facets_for_a_signed_in_visitor_when_a_facet_is_a_custom_field(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
		Catalogue_Preload_Filter_Template::$filters = array( self::CITY, self::SIZE, self::CATEGORY );
		$this->warmFacets( array( 'location.city', 'custom.size' ) );
		$this->warmCategories( 500 );

		$preload = $this->renderedConfig()['preload'] ?? array();

		$this->assertArrayNotHasKey( 'facets', $preload );
		$this->assertArrayHasKey( 'categories', $preload, 'categories are the same for every viewer' );
	}

	#[Test]
	public function should_inline_the_shared_facets_for_an_anonymous_visitor_when_a_facet_is_a_custom_field(): void {
		Catalogue_Preload_Filter_Template::$filters = array( self::CITY, self::SIZE );
		$this->warmFacets( array( 'location.city', 'custom.size' ) );

		$this->assertSame( array( 'location.city', 'custom.size' ), $this->renderedConfig()['preload']['facets']['names'] ?? null );
	}

	#[Test]
	public function should_inline_the_shared_entries_on_the_legacy_card_path_too(): void {
		$this->warmFacets( array( 'location.city', 'tags' ) );

		$html   = agend_apps_records_render_directory_catalogue( array( 'filter_template' => '150' ) );
		$config = $this->configFrom( $html );

		$this->assertSame( 'legacy', $config['cardMode'] ?? null );
		$this->assertSame( array( 'location.city', 'tags' ), $config['preload']['facets']['names'] ?? null );
	}

	#[Test]
	public function should_read_viewer_independent_facets_without_the_member_token_through_the_shared_entry(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );

		agend_apps_directory_get_facets( array( 'location.city', 'tags' ) );

		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
		$this->assertArrayHasKey( $this->facetsTransient( array( 'location.city', 'tags' ) ), Agend_Test_WP::$transients );
		$this->assertArrayHasKey( $this->facetsTransient( array( 'location.city', 'tags' ) ) . '_at', Agend_Test_WP::$transients );
	}

	#[Test]
	public function should_read_custom_field_facets_with_the_member_token_and_store_nothing(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );

		agend_apps_directory_get_facets( array( 'location.city', 'custom.size' ) );
		agend_apps_directory_get_facets( array() );

		$this->assertSame( 'Bearer member-token', Agend_Test_WP::$requests[0]['headers']['Authorization'] ?? null );
		$this->assertSame( 'Bearer member-token', Agend_Test_WP::$requests[1]['headers']['Authorization'] ?? null );
		$this->assertSame( array(), preg_grep( '/^agend_apps_directory_facets/', array_keys( Agend_Test_WP::$transients ) ) );
	}

	#[Test]
	public function should_read_categories_without_the_member_token_through_the_shared_entry(): void {
		Agend_Test_WP::set_filter( 'agend_apps_bearer_token', 'member-token' );

		agend_apps_directory_get_categories();

		$this->assertArrayNotHasKey( 'Authorization', Agend_Test_WP::$requests[0]['headers'] );
		$this->assertArrayHasKey( $this->categoriesTransient( 500 ), Agend_Test_WP::$transients );
		$this->assertArrayHasKey( $this->categoriesTransient( 500 ) . '_at', Agend_Test_WP::$transients );
	}

	/**
	 * @param string[] $names Facet names.
	 * @return array The cached body.
	 */
	private function warmFacets( array $names ): array {
		$body = array( 'data' => array( 'location.city' => array( 'values' => array( array( 'value' => 'Hobart', 'label' => 'Hobart' ) ) ) ) );
		Agend_Test_WP::$transients[ $this->facetsTransient( $names ) ]         = $body;
		Agend_Test_WP::$transients[ $this->facetsTransient( $names ) . '_at' ] = self::FETCHED_AT;
		return $body;
	}

	/**
	 * @return array The cached body.
	 */
	private function warmCategories( int $limit ): array {
		$body = array( 'data' => array( array( 'id' => 'c1', 'name' => 'Clinics', 'parent_id' => null ) ) );
		Agend_Test_WP::$transients[ $this->categoriesTransient( $limit ) ]         = $body;
		Agend_Test_WP::$transients[ $this->categoriesTransient( $limit ) . '_at' ] = self::FETCHED_AT;
		return $body;
	}

	/**
	 * @param string[] $names Facet names.
	 */
	private function facetsTransient( array $names ): string {
		return 'agend_apps_' . Agend_Apps_Cache::build_key( 'directory_facets', array( 'fields' => implode( ',', $names ) ) );
	}

	private function categoriesTransient( int $limit ): string {
		return 'agend_apps_' . Agend_Apps_Cache::build_key(
			'directory_categories',
			array(
				'query' => array(
					'limit'       => $limit,
					'active_only' => true,
					'counts'      => false,
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function renderedConfig(): array {
		return $this->configFrom( agend_apps_records_render_directory_catalogue( array( 'card_template' => '7', 'filter_template' => '150' ) ) );
	}

	/**
	 * The filter configs a render of the filter template records.
	 *
	 * @return array<int, array>
	 */
	private function renderedConfigs(): array {
		return agend_apps_records_directory_catalogue_filters( 150 )['configs'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function configFrom( string $html ): array {
		$this->assertSame( 1, preg_match( '/data-agend-directory-config="([^"]*)"/', $html, $match ) );
		return (array) json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}
}
