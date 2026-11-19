<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use WP_Error;

/**
 * The server half of Agend Filter Shortcuts: a real link for every tile that
 * the catalogue reads back, the values each filter offers, and what is left to
 * the browser.
 *
 * Runs in separate processes so the gateway stubs never reach a test that
 * relies on them being absent.
 */
#[RunTestsInSeparateProcesses]
final class FilterShortcutsRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-widgets-stub.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/query.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/filter-shortcuts.php';
		$GLOBALS['agend_test_dw_requests'] = array();
	}

	/** Reads the config off the data attribute, as the script does. */
	private function config_from( string $html ): array {
		self::assertSame( 1, preg_match( '/data-agend-fs-config="([^"]*)"/', $html, $match ) );
		return (array) json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}

	/** @return string[] The href of every tile. */
	private function hrefs( string $html ): array {
		preg_match_all( '/class="agend-fs__tile" href="([^"]*)"/', $html, $matches );
		return array_map( static fn ( string $href ): string => html_entity_decode( $href, ENT_QUOTES ), $matches[1] );
	}

	/** @return array<string, array{string, string, string, string}> */
	public static function links(): array {
		return array(
			'custom field' => array( 'https://example.test/find/', 'cf_profession', 'Specialist Advisor', 'https://example.test/find/?cf_profession=Specialist%20Advisor' ),
			'category id'  => array( 'https://example.test/find/', 'category_ids', '9b2c1d4e-0000-4000-8000-000000000001', 'https://example.test/find/?category_ids=9b2c1d4e-0000-4000-8000-000000000001' ),
			'plain permalink' => array( 'https://example.test/?page_id=5', 'state', 'NSW', 'https://example.test/?page_id=5&state=NSW' ),
			'comma'        => array( 'https://example.test/find/', 'cf_area', 'a,b', 'https://example.test/find/?cf_area=a%252Cb' ),
			'percent'      => array( 'https://example.test/find/', 'cf_rate', '50%', 'https://example.test/find/?cf_rate=50%2525' ),
			'fragment'     => array( 'https://example.test/find/#results', 'city', 'Sydney', 'https://example.test/find/?city=Sydney#results' ),
			'this page'    => array( '', 'cf_profession', 'Auditor', '?cf_profession=Auditor' ),
		);
	}

	#[Test]
	#[DataProvider( 'links' )]
	public function should_write_the_search_link_parameter_the_way_the_catalogue_encodes_it( string $target, string $param, string $value, string $expected ): void {
		self::assertSame( $expected, agend_apps_records_filter_shortcuts_href( $target, $param, $value ) );
	}

	#[Test]
	public function should_render_a_real_link_for_each_manual_tile_to_the_widgets_directory_page(): void {
		$html = agend_apps_records_render_filter_shortcuts(
			array(
				'filter'           => 'custom_field',
				'custom_field_key' => 'profession',
				'target_url'       => 'https://example.test/find/',
				'items'            => array(
					array( 'label' => 'Advisors', 'value' => 'Specialist Advisor' ),
					array( 'label' => 'Auditors', 'value' => 'Auditor' ),
				),
			)
		);

		self::assertSame(
			array( 'https://example.test/find/?cf_profession=Specialist%20Advisor', 'https://example.test/find/?cf_profession=Auditor' ),
			$this->hrefs( $html )
		);
		self::assertStringContainsString( '<span class="agend-fs__label">Advisors</span>', $html );
		self::assertStringContainsString( 'data-agend-fs-value="Specialist Advisor"', $html );
		self::assertSame( array(), $GLOBALS['agend_test_dw_requests'] );
	}

	#[Test]
	public function should_use_the_label_as_the_value_and_skip_a_row_with_neither(): void {
		$html = agend_apps_records_render_filter_shortcuts(
			array(
				'filter'           => 'custom_field',
				'custom_field_key' => 'profession',
				'items'            => array(
					array( 'label' => 'Auditor', 'value' => '' ),
					array( 'label' => '', 'value' => '' ),
					array( 'label' => '', 'value' => 'Fellow' ),
				),
			)
		);

		self::assertSame( array( '?cf_profession=Auditor', '?cf_profession=Fellow' ), $this->hrefs( $html ) );
		self::assertStringContainsString( '<span class="agend-fs__label">Fellow</span>', $html );
	}

	#[Test]
	public function should_hand_the_script_the_state_key_the_filter_writes(): void {
		$custom = $this->config_from(
			agend_apps_records_render_filter_shortcuts( array( 'filter' => 'custom_field', 'custom_field_key' => 'profession', 'items' => array( array( 'label' => 'A', 'value' => 'A' ) ) ) )
		);
		$state  = $this->config_from(
			agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'items' => array( array( 'label' => 'New South Wales', 'value' => 'NSW' ) ) ) )
		);

		self::assertSame( 'custom_fields', $custom['state'] );
		self::assertSame( 'map', $custom['mode'] );
		self::assertSame( 'profession', $custom['fieldKey'] );
		self::assertSame( 'location_state', $state['state'] );
		self::assertSame( 'array', $state['mode'] );
	}

	#[Test]
	public function should_render_nothing_for_a_custom_field_with_no_key_or_a_list_with_no_tiles(): void {
		self::assertSame( '', agend_apps_records_render_filter_shortcuts( array( 'filter' => 'custom_field', 'items' => array( array( 'label' => 'A', 'value' => 'A' ) ) ) ) );
		self::assertSame( '', agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'items' => array() ) ) );
		self::assertSame( '', agend_apps_records_render_filter_shortcuts( array( 'filter' => 'unknown', 'items' => array( array( 'label' => 'A', 'value' => 'A' ) ) ) ) );
	}

	#[Test]
	public function should_say_what_is_missing_in_an_editor_preview_instead_of_rendering_nothing(): void {
		$html = agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'items' => array() ), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-fs--empty', $html );
	}

	#[Test]
	public function should_make_tiles_from_the_directorys_facet_values_for_a_state_filter(): void {
		$GLOBALS['agend_test_dw_facets'] = array(
			'data' => array(
				'location.state' => array(
					'values' => array(
						array( 'value' => 'NSW', 'label' => 'New South Wales', 'count' => 12 ),
						array( 'value' => 'VIC' ),
						array( 'value' => '' ),
					),
				),
			),
		);

		$html = agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'source' => 'all', 'target_url' => 'https://example.test/find/' ) );

		self::assertSame( array( 'https://example.test/find/?state=NSW', 'https://example.test/find/?state=VIC' ), $this->hrefs( $html ) );
		self::assertStringContainsString( '<span class="agend-fs__label">New South Wales</span>', $html );
		self::assertStringContainsString( '<span class="agend-fs__label">VIC</span>', $html );
		self::assertSame( array( array( 'facets', array( 'location.state' ) ) ), $GLOBALS['agend_test_dw_requests'] );
	}

	#[Test]
	public function should_filter_a_taxonomy_facet_by_its_id_and_a_custom_field_facet_by_its_value(): void {
		$GLOBALS['agend_test_dw_facets'] = array(
			'data' => array(
				'custom.profession' => array( 'values' => array( array( 'value' => 'Auditor', 'label' => 'Auditor' ) ) ),
			),
		);

		$html = agend_apps_records_render_filter_shortcuts( array( 'filter' => 'custom_field', 'custom_field_key' => 'profession', 'source' => 'all' ) );

		self::assertSame( array( '?cf_profession=Auditor' ), $this->hrefs( $html ) );
	}

	#[Test]
	public function should_make_tiles_from_top_level_categories_by_default(): void {
		$GLOBALS['agend_test_dw_categories'] = array(
			'data' => array(
				array( 'id' => 'c1', 'name' => 'Advisory', 'parent_id' => null ),
				array( 'id' => 'c2', 'name' => 'Audit', 'parent_id' => 'c1' ),
				array( 'id' => 'c3', 'name' => 'Tax' ),
			),
		);

		$html = agend_apps_records_render_filter_shortcuts( array( 'filter' => 'category', 'source' => 'all' ) );
		$all  = agend_apps_records_render_filter_shortcuts( array( 'filter' => 'category', 'source' => 'all', 'top_level_only' => '' ) );

		self::assertSame( array( '?category_ids=c1', '?category_ids=c3' ), $this->hrefs( $html ) );
		self::assertSame( array( '?category_ids=c1', '?category_ids=c2', '?category_ids=c3' ), $this->hrefs( $all ) );
	}

	#[Test]
	public function should_render_nothing_when_the_directory_cannot_be_read(): void {
		$GLOBALS['agend_test_dw_facets'] = new WP_Error( 'agend_api_error', 'down' );

		self::assertSame( '', agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'source' => 'all' ) ) );
	}

	#[Test]
	public function should_leave_custom_field_values_to_the_browser_for_a_signed_in_visitor(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;

		$html   = agend_apps_records_render_filter_shortcuts( array( 'filter' => 'custom_field', 'custom_field_key' => 'profession', 'source' => 'all' ) );
		$config = $this->config_from( $html );

		self::assertSame( array(), $this->hrefs( $html ) );
		self::assertSame( 'custom.profession', $config['pending']['facet'] );
		self::assertSame( array(), $GLOBALS['agend_test_dw_requests'] );
	}

	#[Test]
	public function should_still_draw_a_signed_in_visitors_state_tiles_on_the_server(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
		$GLOBALS['agend_test_dw_facets']       = array( 'data' => array( 'location.state' => array( 'values' => array( array( 'value' => 'QLD' ) ) ) ) );

		$html = agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'source' => 'all' ) );

		self::assertSame( array( '?state=QLD' ), $this->hrefs( $html ) );
		self::assertNull( $this->config_from( $html )['pending'] );
	}

	#[Test]
	public function should_show_an_icon_on_a_tile_only_when_icons_are_on_and_keep_only_icon_markup(): void {
		$settings = array(
			'filter' => 'state',
			'items'  => array( array( 'label' => 'NSW', 'value' => 'NSW', 'icon_html' => '<i class="fas fa-map" aria-hidden="true"></i><script>alert(1)</script>' ) ),
		);

		$on  = agend_apps_records_render_filter_shortcuts( $settings );
		$off = agend_apps_records_render_filter_shortcuts( $settings + array( 'show_icons' => '' ) );

		self::assertStringContainsString( '<span class="agend-fs__icon" aria-hidden="true"><i class="fas fa-map" aria-hidden="true"></i>', $on );
		self::assertStringNotContainsString( '<script', $on );
		self::assertStringNotContainsString( 'agend-fs__icon', $off );
		self::assertArrayHasKey( 'svg', agend_apps_records_filter_shortcuts_icon_kses() );
	}

	#[Test]
	public function should_hold_the_column_counts_to_their_ranges(): void {
		$html = agend_apps_records_render_filter_shortcuts(
			array(
				'filter'         => 'state',
				'items'          => array( array( 'label' => 'A', 'value' => 'A' ) ),
				'columns'        => 40,
				'columns_tablet' => 0,
				'columns_mobile' => 'x',
			)
		);

		self::assertStringContainsString( '--agend-fs-cols:8;--agend-fs-cols-tablet:1;--agend-fs-cols-mobile:2', $html );
	}

	#[Test]
	public function should_hand_the_script_whether_to_scroll(): void {
		$items = array( array( 'label' => 'A', 'value' => 'A' ) );

		self::assertTrue( $this->config_from( agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'items' => $items ) ) )['scroll'] );
		self::assertFalse( $this->config_from( agend_apps_records_render_filter_shortcuts( array( 'filter' => 'state', 'items' => $items, 'scroll_to_results' => '' ) ) )['scroll'] );
	}
}
