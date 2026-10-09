<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/detail-login.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fields.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fragments.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-field.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/blocks.php';

/**
 * The two new controls ("Item list layout" and "Item fields") on the Directory
 * catalogue's Detail View and on the Agend Field surface, how they reach the
 * client config and the block, and how the server-rendered detail finds the
 * catalogue's choice on the host page.
 */
#[CoversFunction( 'agend_apps_records_directory_catalogue_build_config' )]
#[CoversFunction( 'agend_apps_records_ssr_item_list_options' )]
#[CoversFunction( 'agend_apps_records_ssr_first_block_attrs' )]
#[CoversFunction( 'agend_apps_records_render_directory_detail' )]
final class ItemListControlsTest extends TestCase {

	/**
	 * A surface's fields by name, across its content sections.
	 *
	 * @param string $surface Surface id.
	 * @return array<string, array<string, mixed>>
	 */
	private function fieldsOf( string $surface ): array {
		$fields = array();
		foreach ( agend_apps_records_surface_schema( $surface )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Every field name a surface declares, in declaration order, duplicates kept.
	 *
	 * @param string $surface Surface id.
	 * @return string[]
	 */
	private function namesOf( string $surface ): array {
		$names = array();
		foreach ( agend_apps_records_surface_schema( $surface )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$names[] = $field['name'];
			}
		}

		return $names;
	}

	/**
	 * A listing in the gateway's shape with one item list custom field.
	 *
	 * @param array<string, mixed> $overrides Item list entry keys to replace.
	 * @return array<string, mixed>
	 */
	private function listing( array $overrides = array() ): array {
		return array(
			'name'          => 'Sample Centre',
			'custom_fields' => array(
				$overrides + array(
					'key'         => 'tenants',
					'label'       => 'Tenants',
					'type'        => 'item_list',
					'value'       => array(
						array( 'name' => 'Cafe', 'area_sqm' => 85 ),
						array( 'name' => 'Department Store', 'area_sqm' => 12500 ),
					),
					'item_fields' => array(
						array( 'key' => 'name', 'label' => 'Tenant', 'type' => 'text' ),
						array( 'key' => 'area_sqm', 'label' => 'Area (sqm)', 'type' => 'number' ),
					),
				),
			),
		);
	}

	private function host( string $content ): WP_Post {
		return new WP_Post( array( 'ID' => 42, 'post_content' => $content ) );
	}

	// -- the controls ----------------------------------------------------------

	#[Test]
	public function should_declare_both_controls_in_the_catalogue_detail_view_section_with_defaults(): void {
		$fields = array();
		foreach ( agend_apps_records_surface_schema( 'directory-catalogue' )['sections'] as $section ) {
			if ( 'section_detail' === $section['id'] ) {
				$fields = array_column( $section['fields'], null, 'name' );
			}
		}

		self::assertSame( 'Item list layout', $fields['item_list_layout']['label'] );
		self::assertSame( 'select', $fields['item_list_layout']['type'] );
		self::assertSame( 'table', $fields['item_list_layout']['default'] );
		self::assertSame( array( 'table' => 'Table', 'list' => 'List' ), $fields['item_list_layout']['options'] );
		self::assertSame( 'Item fields', $fields['item_list_fields']['label'] );
		self::assertSame( 'text', $fields['item_list_fields']['type'] );
		self::assertSame( '', $fields['item_list_fields']['default'] );
		self::assertArrayNotHasKey( 'condition', $fields['item_list_layout'] );
		self::assertArrayNotHasKey( 'condition', $fields['item_list_fields'] );
	}

	#[Test]
	public function should_declare_both_controls_on_the_agend_field_surface_for_a_custom_field_only(): void {
		$fields = $this->fieldsOf( 'record-field' );

		self::assertSame( 'select', $fields['item_list_layout']['type'] );
		self::assertSame( 'table', $fields['item_list_layout']['default'] );
		self::assertSame( array( 'table', 'list' ), array_keys( $fields['item_list_layout']['options'] ) );
		self::assertSame( 'text', $fields['item_list_fields']['type'] );
		self::assertSame( '', $fields['item_list_fields']['default'] );
		self::assertSame( array( 'field' => 'common:custom_field' ), $fields['item_list_layout']['condition'] );
		self::assertSame( array( 'field' => 'common:custom_field' ), $fields['item_list_fields']['condition'] );
	}

	#[Test]
	public function should_keep_every_existing_control_name_and_never_repeat_one(): void {
		$catalogue = $this->namesOf( 'directory-catalogue' );
		$field     = $this->namesOf( 'record-field' );

		foreach ( array( 'show_reviews', 'show_review_form', 'require_login_for_detail', 'pagination_style', 'per_page' ) as $name ) {
			self::assertContains( $name, $catalogue );
		}
		foreach ( array( 'field', 'custom_field_key_choice', 'custom_field_key', 'html_tag', 'before_text', 'after_text', 'fallback_text', 'restricted_text', 'list_separator', 'list_max', 'truncate_chars' ) as $name ) {
			self::assertContains( $name, $field );
		}
		self::assertSame( $catalogue, array_values( array_unique( $catalogue ) ) );
		self::assertSame( $field, array_values( array_unique( $field ) ) );
	}

	#[Test]
	public function should_reach_the_blocks_as_string_attributes_and_come_back_as_settings(): void {
		foreach ( array( 'directory-catalogue', 'record-field' ) as $surface ) {
			$schema     = agend_apps_records_surface_schema( $surface );
			$attributes = agend_apps_records_block_attributes( $schema );

			self::assertSame( array( 'type' => 'string', 'default' => 'table' ), $attributes['item_list_layout'], $surface );
			self::assertSame( array( 'type' => 'string', 'default' => '' ), $attributes['item_list_fields'], $surface );

			$settings = agend_apps_records_settings_from_attributes( $schema, array( 'item_list_layout' => 'list', 'item_list_fields' => 'name,area_sqm' ) );

			self::assertSame( 'list', $settings['item_list_layout'], $surface );
			self::assertSame( 'name,area_sqm', $settings['item_list_fields'], $surface );
		}
	}

	// -- the client config -----------------------------------------------------

	#[Test]
	public function should_leave_the_client_config_unchanged_for_the_default_table_of_every_field(): void {
		$config = agend_apps_records_directory_catalogue_build_config( array() );

		self::assertArrayNotHasKey( 'itemList', $config['detail'] );
		self::assertArrayNotHasKey( 'itemList', agend_apps_records_directory_catalogue_build_config( array( 'item_list_layout' => 'table', 'item_list_fields' => '  ' ) )['detail'] );
	}

	#[Test]
	public function should_hand_the_layout_and_item_fields_to_the_script_when_they_are_not_the_default(): void {
		$list   = agend_apps_records_directory_catalogue_build_config( array( 'item_list_layout' => 'list' ) );
		$fields = agend_apps_records_directory_catalogue_build_config( array( 'item_list_fields' => 'name, area_sqm' ) );

		self::assertSame( array( 'layout' => 'list', 'fields' => array() ), $list['detail']['itemList'] );
		self::assertSame( array( 'layout' => 'table', 'fields' => array( 'name', 'area_sqm' ) ), $fields['detail']['itemList'] );
	}

	// -- the server-rendered detail --------------------------------------------

	#[Test]
	public function should_default_to_a_table_of_every_field_when_the_page_has_no_catalogue(): void {
		$options = agend_apps_records_ssr_item_list_options( $this->listing(), $this->host( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) );

		self::assertSame( array( 'layout' => 'table', 'fields' => array() ), $options );
	}

	#[Test]
	public function should_read_the_choice_from_the_block_editor_catalogue_on_the_host_page(): void {
		$content = '<!-- wp:agend-apps/directory-catalogue {"item_list_layout":"list","item_list_fields":"name"} /-->';

		self::assertSame(
			array( 'layout' => 'list', 'fields' => array( 'name' ) ),
			agend_apps_records_ssr_item_list_options( $this->listing(), $this->host( $content ) )
		);
	}

	#[Test]
	public function should_find_a_catalogue_nested_in_other_blocks_and_use_the_first_one(): void {
		$content = '<!-- wp:group --><div><!-- wp:agend-apps/directory-catalogue {"item_list_layout":"list"} /-->'
			. '<!-- wp:agend-apps/directory-catalogue {"item_list_fields":"area_sqm"} /--></div><!-- /wp:group -->';

		self::assertSame(
			array( 'layout' => 'list', 'fields' => array() ),
			agend_apps_records_ssr_item_list_options( $this->listing(), $this->host( $content ) )
		);
	}

	#[Test]
	public function should_ignore_the_item_list_attributes_of_any_other_block(): void {
		$content = '<!-- wp:agend-apps/record-field {"item_list_layout":"list"} /-->';

		self::assertSame( 'table', agend_apps_records_ssr_item_list_options( $this->listing(), $this->host( $content ) )['layout'] );
	}

	#[Test]
	public function should_let_a_page_builder_supply_the_settings_through_the_filter(): void {
		$seen = array();
		add_filter(
			'agend_apps_records_directory_detail_settings',
			static function ( $settings, $host ) use ( &$seen ) {
				$seen = array( $settings, $host->ID );
				return array( 'item_list_layout' => 'list', 'item_list_fields' => 'name,area_sqm' );
			},
			10,
			2
		);

		$options = agend_apps_records_ssr_item_list_options( $this->listing(), $this->host( '' ) );

		self::assertSame( array( 'layout' => 'list', 'fields' => array( 'name', 'area_sqm' ) ), $options );
		self::assertSame( array( array(), 42 ), $seen );
	}

	#[Test]
	public function should_pass_the_block_editor_settings_to_the_filter_so_a_builder_can_defer_to_them(): void {
		$seen = null;
		add_filter(
			'agend_apps_records_directory_detail_settings',
			static function ( $settings ) use ( &$seen ) {
				$seen = $settings;
				return $settings;
			}
		);

		agend_apps_records_ssr_item_list_options( $this->listing(), $this->host( '<!-- wp:agend-apps/directory-catalogue {"item_list_layout":"list"} /-->' ) );

		self::assertSame( array( 'item_list_layout' => 'list' ), $seen );
	}

	#[Test]
	public function should_not_look_for_settings_when_the_listing_has_no_readable_item_list(): void {
		$consulted = false;
		add_filter(
			'agend_apps_records_directory_detail_settings',
			static function ( $settings ) use ( &$consulted ) {
				$consulted = true;
				return $settings;
			}
		);

		$plain = array( 'custom_fields' => array( array( 'key' => 'abn', 'label' => 'ABN', 'type' => 'text', 'value' => '1' ) ) );
		$gated = $this->listing( array( 'gated' => true, 'state' => 'plan_required' ) );

		self::assertSame( array(), agend_apps_records_ssr_item_list_options( $plain, $this->host( '' ) ) );
		self::assertSame( array(), agend_apps_records_ssr_item_list_options( $gated, $this->host( '' ) ) );
		self::assertSame( array(), agend_apps_records_ssr_item_list_options( array(), $this->host( '' ) ) );
		self::assertFalse( $consulted );
	}

	#[Test]
	public function should_draw_the_detail_with_the_layout_chosen_on_the_page(): void {
		Agend_Test_WP::$options['permalink_structure'] = '/%postname%/';
		$GLOBALS['agend_test_posts'][42]               = array( 'ID' => 42, 'post_type' => 'page', 'post_status' => 'publish' );

		$table = agend_apps_records_render_directory_detail( $this->listing(), 'sample-centre', null, $this->host( '<!-- wp:agend-apps/directory-catalogue /-->' ) );
		$list  = agend_apps_records_render_directory_detail( $this->listing(), 'sample-centre', null, $this->host( '<!-- wp:agend-apps/directory-catalogue {"item_list_layout":"list","item_list_fields":"name"} /-->' ) );

		self::assertStringContainsString( '<table class="agend-dir-cf__item-list agend-dir-cf__item-list--table">', $table );
		self::assertStringContainsString( '<td class="agend-dir-cf__item-list-cell--number">12,500</td>', $table );
		self::assertStringContainsString( '<ul class="agend-dir-cf__item-list agend-dir-cf__item-list--list"><li>Cafe</li><li>Department Store</li></ul>', $list );
		self::assertStringNotContainsString( '12,500', $list );
	}
}
