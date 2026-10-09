<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Elementor_Detail_Settings;
use Agend_Test_WP;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WP_Post;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/settings.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/pages.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/detail-login.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/format.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/fragments.php';
require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/ssr-detail.php';
require_once AGEND_TESTS_ROOT . '/agend-elementor/includes/class-agend-elementor-detail-settings.php';

/**
 * {@see Agend_Elementor_Detail_Settings}: the Elementor side of the Directory
 * Catalogue settings a server-rendered listing detail reads from its host page,
 * so "Item list layout" and "Item fields" set on the widget reach a detail page
 * that never renders the widget.
 */
#[CoversClass( Agend_Elementor_Detail_Settings::class )]
final class DetailSettingsTest extends TestCase {

	/**
	 * A widget element, in `_elementor_data` shape.
	 *
	 * @param string $widget_type Elementor widget type.
	 * @param array  $settings    Widget settings.
	 * @return array<string, mixed>
	 */
	private function widget( string $widget_type, array $settings ): array {
		return array(
			'id'         => 'w' . substr( md5( $widget_type . wp_json_encode( $settings ) ), 0, 6 ),
			'elType'     => 'widget',
			'widgetType' => $widget_type,
			'settings'   => $settings,
		);
	}

	/**
	 * @param array $elements Child elements.
	 * @return array<string, mixed>
	 */
	private function container( array $elements ): array {
		return array(
			'id'       => 'c1',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => $elements,
		);
	}

	private function seedPage( int $id, ?array $tree, bool $built_with_elementor = true ): WP_Post {
		$GLOBALS['agend_test_posts'][ $id ] = array(
			'ID'          => $id,
			'post_type'   => 'page',
			'post_status' => 'publish',
		);

		if ( null !== $tree ) {
			$GLOBALS['agend_test_post_meta'][ $id ]['_elementor_data'] = wp_json_encode( $tree );
		}
		if ( $built_with_elementor ) {
			$GLOBALS['agend_test_post_meta'][ $id ]['_elementor_edit_mode'] = 'builder';
		}

		return new WP_Post( array( 'ID' => $id ) );
	}

	/**
	 * A listing with one item list custom field.
	 *
	 * @return array<string, mixed>
	 */
	private function listing(): array {
		return array(
			'custom_fields' => array(
				array(
					'key'         => 'tenants',
					'label'       => 'Tenants',
					'type'        => 'item_list',
					'value'       => array( array( 'name' => 'Cafe', 'area_sqm' => 85 ) ),
					'item_fields' => array(
						array( 'key' => 'name', 'label' => 'Tenant', 'type' => 'text' ),
						array( 'key' => 'area_sqm', 'label' => 'Area', 'type' => 'number' ),
					),
				),
			),
		);
	}

	#[Test]
	public function should_find_the_catalogue_settings_at_any_depth(): void {
		$settings = array( 'item_list_layout' => 'list', 'item_list_fields' => 'name' );
		$tree     = array( $this->widget( 'heading', array( 'title' => 'Hi' ) ), $this->container( array( $this->container( array( $this->widget( 'agend-directory-catalogue', $settings ) ) ) ) ) );

		self::assertSame( $settings, Agend_Elementor_Detail_Settings::from_elements( $tree ) );
	}

	#[Test]
	public function should_use_the_first_catalogue_when_a_page_holds_several(): void {
		$tree = array(
			$this->widget( 'agend-directory-catalogue', array( 'item_list_layout' => 'list' ) ),
			$this->widget( 'agend-directory-catalogue', array( 'item_list_fields' => 'area_sqm' ) ),
		);

		self::assertSame( array( 'item_list_layout' => 'list' ), Agend_Elementor_Detail_Settings::from_elements( $tree ) );
	}

	#[Test]
	public function should_answer_with_no_settings_for_a_catalogue_left_at_its_defaults(): void {
		self::assertSame( array(), Agend_Elementor_Detail_Settings::from_elements( array( $this->widget( 'agend-directory-catalogue', array() ) ) ) );
	}

	#[Test]
	public function should_find_nothing_on_a_page_without_a_directory_catalogue(): void {
		$tree = array(
			$this->widget( 'agend-events-catalogue', array( 'item_list_layout' => 'list' ) ),
			array( 'elType' => 'container', 'settings' => array( 'item_list_layout' => 'list' ), 'elements' => array() ),
		);

		self::assertNull( Agend_Elementor_Detail_Settings::from_elements( $tree ) );
		self::assertNull( Agend_Elementor_Detail_Settings::from_elements( array() ) );
	}

	#[Test]
	public function should_read_a_saved_document_and_tolerate_missing_or_unreadable_data(): void {
		$this->seedPage( 42, array( $this->widget( 'agend-directory-catalogue', array( 'item_list_layout' => 'list' ) ) ) );
		$this->seedPage( 43, null );
		$this->seedPage( 44, null );
		$GLOBALS['agend_test_post_meta'][44]['_elementor_data'] = '{not json';

		self::assertSame( array( 'item_list_layout' => 'list' ), Agend_Elementor_Detail_Settings::for_post( 42 ) );
		self::assertNull( Agend_Elementor_Detail_Settings::for_post( 43 ) );
		self::assertNull( Agend_Elementor_Detail_Settings::for_post( 44 ) );
	}

	#[Test]
	public function should_ignore_element_data_left_on_a_page_elementor_no_longer_builds(): void {
		$tree = array( $this->widget( 'agend-directory-catalogue', array( 'item_list_layout' => 'list' ) ) );
		$host = $this->seedPage( 42, $tree, false );

		self::assertNull( Agend_Elementor_Detail_Settings::for_post( 42 ) );
		self::assertSame( array(), Agend_Elementor_Detail_Settings::filter( array(), $host ) );

		$GLOBALS['agend_test_post_meta'][42]['_elementor_edit_mode'] = '';
		self::assertNull( Agend_Elementor_Detail_Settings::for_post( 42 ) );

		$GLOBALS['agend_test_post_meta'][42]['_elementor_edit_mode'] = 'builder';
		self::assertSame( array( 'item_list_layout' => 'list' ), Agend_Elementor_Detail_Settings::for_post( 42 ) );
	}

	#[Test]
	public function should_use_the_defaults_in_the_server_rendered_detail_for_a_page_switched_back_to_the_block_editor(): void {
		Agend_Elementor_Detail_Settings::init();
		$host = $this->seedPage( 42, array( $this->widget( 'agend-directory-catalogue', array( 'item_list_layout' => 'list' ) ) ), false );

		self::assertSame( array( 'layout' => 'table', 'fields' => array() ), agend_apps_records_ssr_item_list_options( $this->listing(), $host ) );
	}

	#[Test]
	public function should_answer_the_core_filter_only_when_nothing_earlier_found_a_catalogue(): void {
		$host = $this->seedPage( 42, array( $this->widget( 'agend-directory-catalogue', array( 'item_list_layout' => 'list' ) ) ) );

		self::assertSame( array( 'item_list_layout' => 'list' ), Agend_Elementor_Detail_Settings::filter( array(), $host ) );
		self::assertSame( array( 'item_list_layout' => 'table' ), Agend_Elementor_Detail_Settings::filter( array( 'item_list_layout' => 'table' ), $host ) );
		self::assertSame( array(), Agend_Elementor_Detail_Settings::filter( array(), $this->seedPage( 43, null ) ) );
		self::assertSame( array(), Agend_Elementor_Detail_Settings::filter( array(), 'not a post' ) );
	}

	#[Test]
	public function should_reach_the_server_rendered_detail_through_the_core_filter(): void {
		Agend_Elementor_Detail_Settings::init();
		$host = $this->seedPage( 42, array( $this->widget( 'agend-directory-catalogue', array( 'item_list_layout' => 'list', 'item_list_fields' => 'name' ) ) ) );

		self::assertArrayHasKey( 'agend_apps_records_directory_detail_settings', Agend_Test_WP::$filters );
		self::assertSame(
			array( 'layout' => 'list', 'fields' => array( 'name' ) ),
			agend_apps_records_ssr_item_list_options( $this->listing(), $host )
		);
	}

	#[Test]
	public function should_default_to_a_table_for_an_elementor_page_left_at_the_defaults(): void {
		Agend_Elementor_Detail_Settings::init();
		$host = $this->seedPage( 42, array( $this->widget( 'agend-directory-catalogue', array() ) ) );

		self::assertSame( array( 'layout' => 'table', 'fields' => array() ), agend_apps_records_ssr_item_list_options( $this->listing(), $host ) );
	}
}
