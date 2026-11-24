<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;

/**
 * The server half of Agend Directory Search: the form that works without
 * script, the place field and locate link that wait for it, and the config
 * the page script reads.
 *
 * Runs in separate processes so the pages and settings files loaded here do not
 * reach a test that relies on them being absent.
 */
#[RunTestsInSeparateProcesses]
final class DirectorySearchRenderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-search.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/tests/fixtures/directory-widgets-stub.php';
		$GLOBALS['agend_test_dw_requests'] = array();
	}

	/** A Profession dropdown row, as the repeater saves it. */
	private static function profession_row( array $extra = array() ): array {
		return $extra + array(
			'dropdown_filter'           => 'custom_field',
			'dropdown_custom_field_key' => 'profession',
			'dropdown_label'            => 'Profession',
			'dropdown_any_label'        => 'All professions',
		);
	}

	private function give_professions( array $values ): void {
		$GLOBALS['agend_test_dw_facets'] = array(
			'data' => array( 'custom.profession' => array( 'values' => $values ) ),
		);
	}

	/** Reads the config off the data attribute, as the script does. */
	private function config_from( string $html ): array {
		self::assertSame( 1, preg_match( '/data-agend-ds-config="([^"]*)"/', $html, $match ) );
		return (array) json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}

	private function dedicate_directory_page( int $id = 5 ): void {
		$GLOBALS['agend_test_posts'][ $id ]                                   = array(
			'ID'          => $id,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
		Agend_Test_WP::$options[ AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION ] = $id;
	}

	#[Test]
	public function should_render_a_get_form_with_a_named_keyword_field_and_no_name_on_the_place_field(): void {
		$html = agend_apps_records_render_directory_search( array() );

		self::assertStringContainsString( '<form class="agend-ds__form" role="search" method="get"', $html );
		self::assertSame( 1, substr_count( $html, ' name="q"' ) );
		self::assertSame( 1, preg_match( '/agend-ds__field--location".*?<input[^>]*>/s', $html, $match ) );
		self::assertStringNotContainsString( 'name=', $match[0] );
	}

	#[Test]
	public function should_give_every_field_a_label_for_screen_readers_and_the_placeholders_as_set(): void {
		$html = agend_apps_records_render_directory_search(
			array(
				'keyword_label'        => 'Name or firm',
				'keyword_placeholder'  => 'Search by name',
				'location_label'       => 'Suburb',
				'location_placeholder' => 'Where?',
			)
		);

		self::assertStringContainsString( '<span class="agend-ds__label">Name or firm</span>', $html );
		self::assertStringContainsString( 'placeholder="Search by name"', $html );
		self::assertStringContainsString( '<span class="agend-ds__label">Suburb</span>', $html );
		self::assertStringContainsString( 'placeholder="Where?"', $html );
	}

	#[Test]
	public function should_leave_out_each_part_the_settings_turn_off(): void {
		$html = agend_apps_records_render_directory_search(
			array(
				'show_keyword' => '',
				'show_location' => '',
				'show_locate'  => '',
			)
		);

		self::assertStringNotContainsString( 'agend-ds__field', $html );
		self::assertStringNotContainsString( 'agend-ds__locate', $html );
		self::assertStringContainsString( 'agend-ds__button', $html );
	}

	#[Test]
	public function should_keep_script_only_parts_hidden_from_a_page_without_script_by_marking_them(): void {
		$html = agend_apps_records_render_directory_search( array() );

		self::assertSame( 2, substr_count( $html, 'data-agend-ds-js' ) );
		self::assertStringNotContainsString( 'agend-ds--js', $html );
	}

	#[Test]
	public function should_show_the_script_only_parts_in_an_editor_preview(): void {
		$html = agend_apps_records_render_directory_search( array(), array( 'preview' => true ) );

		self::assertStringContainsString( 'agend-ds agend-ds--icons agend-ds--js', $html );
	}

	#[Test]
	public function should_use_the_button_and_locate_text_as_set(): void {
		$html = agend_apps_records_render_directory_search( array( 'button_text' => 'Find', 'locate_text' => 'Near me' ) );

		self::assertStringContainsString( '>Find</button>', $html );
		self::assertStringContainsString( '<span>Near me</span>', $html );
	}

	#[Test]
	public function should_draw_no_icons_when_the_setting_is_off(): void {
		$html = agend_apps_records_render_directory_search( array( 'show_icons' => '' ) );

		self::assertStringNotContainsString( '<svg', $html );
		self::assertStringNotContainsString( 'agend-ds--icons', $html );
	}

	#[Test]
	public function should_escape_text_set_by_an_author(): void {
		$html = agend_apps_records_render_directory_search( array( 'keyword_placeholder' => '"><script>x</script>', 'button_text' => '<b>Go</b>' ) );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>Go</b>', $html );
	}

	#[Test]
	public function should_submit_to_the_dedicated_directory_page_when_no_page_is_set_on_the_widget(): void {
		$this->dedicate_directory_page();

		$html = agend_apps_records_render_directory_search( array() );

		self::assertStringContainsString( 'action="https://example.test/page-5/"', $html );
		self::assertSame( 'https://example.test/page-5/', $this->config_from( $html )['target'] );
	}

	#[Test]
	public function should_prefer_the_page_set_on_the_widget_to_the_dedicated_directory_page(): void {
		$this->dedicate_directory_page();

		$html = agend_apps_records_render_directory_search( array( 'target_url' => array( 'url' => 'https://example.test/find/' ) ) );

		self::assertStringContainsString( 'action="https://example.test/find/"', $html );
	}

	#[Test]
	public function should_carry_the_query_of_a_plain_permalink_target_as_hidden_inputs(): void {
		$html = agend_apps_records_render_directory_search( array( 'target_url' => 'https://example.test/?page_id=12&lang=en' ) );

		self::assertStringContainsString( 'action="https://example.test/"', $html );
		self::assertStringContainsString( '<input type="hidden" name="page_id" value="12">', $html );
		self::assertStringContainsString( '<input type="hidden" name="lang" value="en">', $html );
	}

	#[Test]
	public function should_submit_to_the_same_page_when_there_is_no_directory_page(): void {
		$html = agend_apps_records_render_directory_search( array() );

		self::assertStringContainsString( 'action=""', $html );
		self::assertSame( '', $this->config_from( $html )['target'] );
	}

	#[Test]
	public function should_hand_the_script_the_region_scroll_setting_and_a_radius_held_to_the_gateway_range(): void {
		$config = $this->config_from( agend_apps_records_render_directory_search( array( 'location_region' => ' Australia ', 'scroll_to_results' => '', 'radius' => 5000 ) ) );

		self::assertSame( 'Australia', $config['region'] );
		self::assertFalse( $config['scroll'] );
		self::assertSame( 1000.0, (float) $config['radius'] );
	}

	#[Test]
	public function should_default_to_scrolling_and_a_fifteen_kilometre_radius(): void {
		$config = $this->config_from( agend_apps_records_render_directory_search( array() ) );

		self::assertTrue( $config['scroll'] );
		self::assertSame( 15.0, (float) $config['radius'] );
		self::assertSame( 'My location', $config['text']['myLocation'] );
	}

	#[Test]
	public function should_draw_a_dropdown_of_the_directorys_values_between_the_keyword_and_place_fields(): void {
		$this->give_professions(
			array(
				array( 'value' => 'Accountant', 'label' => 'Accountant', 'count' => 640 ),
				array( 'value' => 'SMSF Administration' ),
				array( 'value' => '' ),
			)
		);

		$html = agend_apps_records_render_directory_search( array( 'dropdowns' => array( self::profession_row() ) ) );

		self::assertSame( 1, preg_match( '/agend-ds__field--keyword.*agend-ds__field--choice.*agend-ds__field--location/s', $html ) );
		self::assertStringContainsString( '<span class="agend-ds__label">Profession</span>', $html );
		self::assertStringContainsString( '<select class="agend-ds__input agend-ds__select is-empty" name="cf_profession"><option value="">All professions</option>', $html );
		self::assertStringContainsString( '<option value="Accountant" data-agend-ds-value="Accountant">Accountant</option>', $html );
		self::assertStringContainsString( '<option value="SMSF Administration" data-agend-ds-value="SMSF Administration">SMSF Administration</option>', $html );
		self::assertSame( 3, substr_count( $html, '<option ' ) );
		self::assertSame( array( array( 'facets', array( 'custom.profession' ) ) ), $GLOBALS['agend_test_dw_requests'] );
	}

	#[Test]
	public function should_write_an_options_value_the_way_a_search_link_carries_it_and_keep_the_plain_value_for_the_script(): void {
		$this->give_professions( array( array( 'value' => 'Tax, audit', 'label' => 'Tax, audit' ), array( 'value' => '50%' ) ) );

		$html = agend_apps_records_render_directory_search( array( 'dropdowns' => array( self::profession_row() ) ) );

		self::assertStringContainsString( '<option value="Tax%2C audit" data-agend-ds-value="Tax, audit">Tax, audit</option>', $html );
		self::assertStringContainsString( '<option value="50%25" data-agend-ds-value="50%">50%</option>', $html );
	}

	#[Test]
	public function should_hand_the_script_the_state_key_each_dropdown_writes_in_the_order_drawn(): void {
		$this->give_professions( array( array( 'value' => 'Auditor' ) ) );
		$GLOBALS['agend_test_dw_facets']['data']['location.state'] = array( 'values' => array( array( 'value' => 'NSW' ) ) );

		$config = $this->config_from(
			agend_apps_records_render_directory_search(
				array( 'dropdowns' => array( self::profession_row(), array( 'dropdown_filter' => 'state' ) ) )
			)
		);

		self::assertSame(
			array(
				array( 'state' => 'custom_fields', 'mode' => 'map', 'fieldKey' => 'profession', 'facet' => null ),
				array( 'state' => 'location_state', 'mode' => 'array', 'fieldKey' => '', 'facet' => null ),
			),
			$config['choices']
		);
	}

	#[Test]
	public function should_name_a_state_dropdown_for_the_state_parameter_and_fall_back_to_the_filters_name_and_any(): void {
		$GLOBALS['agend_test_dw_facets'] = array( 'data' => array( 'location.state' => array( 'values' => array( array( 'value' => 'NSW', 'label' => 'New South Wales' ) ) ) ) );

		$html = agend_apps_records_render_directory_search( array( 'dropdowns' => array( array( 'dropdown_filter' => 'state' ) ) ) );

		self::assertStringContainsString( 'name="state"><option value="">Any</option><option value="NSW" data-agend-ds-value="NSW">New South Wales</option>', $html );
		self::assertStringContainsString( '<span class="agend-ds__label">State</span>', $html );
	}

	#[Test]
	public function should_skip_a_dropdown_with_no_usable_filter_or_no_values_but_show_it_empty_in_an_editor_preview(): void {
		$rows = array(
			array( 'dropdown_filter' => 'custom_field', 'dropdown_custom_field_key' => '' ),
			array( 'dropdown_filter' => 'unknown' ),
			self::profession_row(),
		);

		$page    = agend_apps_records_render_directory_search( array( 'dropdowns' => $rows ) );
		$preview = agend_apps_records_render_directory_search( array( 'dropdowns' => $rows ), array( 'preview' => true ) );

		self::assertStringNotContainsString( 'agend-ds__field--choice', $page );
		self::assertSame( array(), $this->config_from( $page )['choices'] );
		self::assertSame( 1, substr_count( $preview, 'agend-ds__field--choice' ) );
		self::assertStringContainsString( 'name="cf_profession"><option value="">All professions</option></select>', $preview );
	}

	#[Test]
	public function should_leave_a_signed_in_visitors_custom_field_values_to_the_browser(): void {
		$GLOBALS['agend_test_current_user_id'] = 7;
		$this->give_professions( array( array( 'value' => 'Auditor' ) ) );

		$html   = agend_apps_records_render_directory_search( array( 'dropdowns' => array( self::profession_row() ) ) );
		$config = $this->config_from( $html );

		self::assertStringContainsString( 'name="cf_profession"><option value="">All professions</option></select>', $html );
		self::assertSame( 'custom.profession', $config['choices'][0]['facet'] );
		self::assertSame( array(), $GLOBALS['agend_test_dw_requests'] );
	}

	#[Test]
	public function should_escape_a_dropdowns_labels_and_values(): void {
		$this->give_professions( array( array( 'value' => '"><script>x</script>', 'label' => '<b>Bold</b>' ) ) );

		$html = agend_apps_records_render_directory_search(
			array( 'dropdowns' => array( self::profession_row( array( 'dropdown_any_label' => '<i>Any</i>' ) ) ) )
		);

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>Bold</b>', $html );
		self::assertStringNotContainsString( '<i>Any</i>', $html );
	}
}
