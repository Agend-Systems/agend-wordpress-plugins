<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend_Apps_Block_Template_Renderer;
use Agend_Apps_Directory_REST_Controller;
use Agend_Apps_Records_Fragments_Controller;
use Agend_Apps_Template_Field_Keys;
use Agend_Apps_Templates;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use WP_REST_Request;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/interface-agend-apps-template-field-keys.php';

/** Reports a fixed key set for template 7 and owns no other id. */
final class Search_Include_Test_Reader implements Agend_Apps_Template_Field_Keys {
	public int $reads = 0;

	/** @var string[] */
	public array $fields;

	/** @var string[] */
	public array $custom_fields;

	public bool $all_custom_fields;

	/**
	 * @param string[] $fields            Bound record field keys.
	 * @param string[] $custom_fields     Bound custom field keys.
	 * @param bool     $all_custom_fields Whether the template shows every custom field.
	 */
	public function __construct( array $fields, array $custom_fields = array(), bool $all_custom_fields = false ) {
		$this->fields            = $fields;
		$this->custom_fields     = $custom_fields;
		$this->all_custom_fields = $all_custom_fields;
	}

	public function field_keys( int $template_id ): ?array {
		if ( 7 !== $template_id ) {
			return null;
		}
		++$this->reads;
		return array(
			'fields'            => $this->fields,
			'custom_fields'     => $this->custom_fields,
			'all_custom_fields' => $this->all_custom_fields,
		);
	}
}

/**
 * A card template asks the directory search for exactly the optional data its
 * fields render, derived on the server and never taken from the request.
 */
final class SearchIncludeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/tests/render-doubles.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/template-field-keys.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/templates/class-agend-apps-block-template-renderer.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-field.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-pills.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/record-image.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/directory-catalogue.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/rest/fragments-controller.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/api/directory-map.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/class-agend-apps-rest-controller.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/rest/directory-routes.php';
		agend_render_test_reset();
		Agend_Apps_Block_Template_Renderer::reset();
		$GLOBALS['agend_test_posts'] = array();
	}

	private function useTemplateBinding( string ...$fields ): Search_Include_Test_Reader {
		$reader = new Search_Include_Test_Reader( $fields );
		Agend_Apps_Templates::register_field_key_reader( $reader );
		return $reader;
	}

	/**
	 * @param string[] $custom_fields Bound custom field keys.
	 */
	private function useCustomFieldBinding( array $custom_fields, bool $all_custom_fields = false ): Search_Include_Test_Reader {
		$reader = new Search_Include_Test_Reader( array( 'common:custom_field', 'listing:name' ), $custom_fields, $all_custom_fields );
		Agend_Apps_Templates::register_field_key_reader( $reader );
		return $reader;
	}

	/**
	 * The first-page search and a card-fragment search for template 7.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function catalogueSearches( array $fragment_params = array() ): array {
		agend_apps_records_render_directory_catalogue( array( 'card_template' => '7' ) );
		( new Agend_Apps_Records_Fragments_Controller() )->get_cards( $this->fragmentsRequest( $fragment_params ), 'listing' );
		return $this->searches();
	}

	/** @return array<int, array<string, mixed>> */
	private function searches(): array {
		return $GLOBALS['agend_render_test_directory_search_args'];
	}

	private function fragmentsRequest( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/cards/listings' );
		foreach ( array( 'template' => 7 ) + $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	// -- What an element binds -------------------------------------------------

	#[Test]
	public function should_report_the_field_an_agend_field_pills_or_image_element_is_bound_to(): void {
		$this->assertSame( array( 'listing:categories' ), agend_apps_records_template_binding_keys( 'record-pills', array( 'field' => 'listing:categories' ) )['fields'] );
		$this->assertSame( array( 'listing:phone' ), agend_apps_records_template_binding_keys( 'record-field', array( 'field' => 'listing:phone' ) )['fields'] );
		$this->assertSame( array( 'listing:logo_url' ), agend_apps_records_template_binding_keys( 'record-image', array( 'field' => 'listing:logo_url' ) )['fields'] );
	}

	#[Test]
	public function should_report_the_schema_default_when_an_element_never_changed_its_field(): void {
		$this->assertSame( array( 'common:title' ), agend_apps_records_template_binding_keys( 'record-field', array() )['fields'] );
		$this->assertSame( array( 'common:category' ), agend_apps_records_template_binding_keys( 'record-pills', array() )['fields'] );
	}

	#[Test]
	public function should_report_the_picked_custom_field_key_over_the_typed_one_when_both_are_set(): void {
		$keys = agend_apps_records_template_binding_keys(
			'record-field',
			array( 'field' => 'common:custom_field', 'custom_field_key_choice' => 'payment_options', 'custom_field_key' => 'typed_key' )
		);

		$this->assertSame( array( 'common:custom_field' ), $keys['fields'] );
		$this->assertSame( array( 'payment_options' ), $keys['custom_fields'] );
	}

	#[Test]
	public function should_report_the_term_field_when_an_agend_image_chooses_its_picture_by_term(): void {
		$by_term = agend_apps_records_template_binding_keys( 'record-image', array( 'use_term_images' => 'yes', 'term_field' => 'listing:categories' ) );
		$default = agend_apps_records_template_binding_keys( 'record-image', array( 'use_term_images' => 'yes' ) );
		$off     = agend_apps_records_template_binding_keys( 'record-image', array( 'term_field' => 'listing:categories' ) );

		$this->assertSame( 'categories', agend_apps_records_search_include_for_keys( $by_term ) );
		$this->assertContains( 'common:category', $default['fields'] );
		$this->assertSame( '', agend_apps_records_search_include_for_keys( $off ), 'the term field is unused while the toggle is off' );
	}

	#[Test]
	public function should_report_nothing_for_an_element_that_binds_no_record_field(): void {
		$this->assertSame( agend_apps_records_empty_binding_keys(), agend_apps_records_template_binding_keys( 'record-block', array( 'block' => 'listing_about' ) ) );
	}

	#[Test]
	public function should_need_all_custom_fields_when_a_panel_shows_every_listing_custom_field(): void {
		$this->assertTrue( agend_apps_records_template_binding_keys( 'record-block', array( 'block' => 'listing_custom_fields' ) )['all_custom_fields'] );
		$this->assertFalse( agend_apps_records_template_binding_keys( 'record-block', array() )['all_custom_fields'], 'a panel left at its default shows event facts' );
	}

	#[Test]
	public function should_need_all_custom_fields_when_a_custom_field_element_has_no_key(): void {
		$this->assertTrue( agend_apps_records_template_binding_keys( 'record-field', array( 'field' => 'common:custom_field' ) )['all_custom_fields'] );
		$this->assertTrue( agend_apps_records_template_binding_keys( 'record-pills', array( 'field' => 'common:custom_field', 'custom_field_key' => ' ' ) )['all_custom_fields'] );
		$this->assertTrue( agend_apps_records_template_binding_keys( 'record-field', array( 'field' => 'common:custom_field_label' ) )['all_custom_fields'] );
		$this->assertFalse( agend_apps_records_template_binding_keys( 'record-field', array( 'field' => 'common:custom_field', 'custom_field_key' => 'a' ) )['all_custom_fields'] );
	}

	#[Test]
	public function should_merge_key_sets_into_distinct_sorted_lists(): void {
		$merged = agend_apps_records_merge_binding_keys(
			array( 'fields' => array( 'listing:phone', 'common:title' ), 'custom_fields' => array( 'b' ) ),
			array( 'fields' => array( 'listing:phone' ), 'custom_fields' => array( 'a', 'b' ) )
		);

		$this->assertSame( array( 'common:title', 'listing:phone' ), $merged['fields'] );
		$this->assertSame( array( 'a', 'b' ), $merged['custom_fields'] );
		$this->assertFalse( $merged['all_custom_fields'] );
	}

	#[Test]
	public function should_need_all_custom_fields_when_any_merged_set_needs_them(): void {
		$merged = agend_apps_records_merge_binding_keys(
			array( 'fields' => array(), 'custom_fields' => array( 'a' ) ),
			array( 'fields' => array(), 'custom_fields' => array(), 'all_custom_fields' => true )
		);

		$this->assertTrue( $merged['all_custom_fields'] );
	}

	// -- The custom fields a template needs --------------------------------------

	#[Test]
	public function should_name_each_bound_custom_field_in_the_facets_vocabulary(): void {
		$this->assertSame( 'custom.a,custom.payment_options', agend_apps_records_search_fields_for_keys( array( 'custom_fields' => array( 'a', 'payment_options' ) ) ) );
	}

	#[Test]
	public function should_name_no_custom_field_when_the_template_binds_none(): void {
		$this->assertSame( '', agend_apps_records_search_fields_for_keys( agend_apps_records_empty_binding_keys() ) );
	}

	#[Test]
	public function should_not_narrow_the_custom_fields_when_the_template_needs_them_all(): void {
		$this->assertNull( agend_apps_records_search_fields_for_keys( array( 'custom_fields' => array( 'a' ), 'all_custom_fields' => true ) ) );
	}

	// -- The include a template needs ------------------------------------------

	#[Test]
	public function should_need_categories_and_phone_when_the_template_binds_both(): void {
		$this->assertSame( 'categories,phone', agend_apps_records_search_include_for_keys( array( 'fields' => array( 'listing:phone', 'listing:categories', 'listing:name' ) ) ) );
	}

	#[Test]
	public function should_need_no_include_when_the_template_binds_neither_categories_nor_phone(): void {
		$this->assertSame( '', agend_apps_records_search_include_for_keys( array( 'fields' => array( 'listing:name', 'common:category', 'listing:locations' ) ) ) );
	}

	// -- The block editor reader ------------------------------------------------

	#[Test]
	public function should_read_every_bound_field_of_a_block_template_at_any_depth(): void {
		$GLOBALS['agend_test_posts'][31] = array(
			'ID'           => 31,
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_content' => '<!-- wp:group --><div><!-- wp:agend-apps/record-pills {"field":"listing:categories"} /--><!-- wp:group --><div><!-- wp:agend-apps/record-field {"field":"listing:phone"} /--></div><!-- /wp:group --></div><!-- /wp:group --><!-- wp:agend-apps/record-field /-->',
		);

		$keys = ( new Agend_Apps_Block_Template_Renderer() )->field_keys( 31 );

		$this->assertSame( array( 'common:title', 'listing:categories', 'listing:phone' ), $keys['fields'] );
	}

	#[Test]
	public function should_report_the_term_field_when_a_block_agend_image_chooses_its_picture_by_term(): void {
		$GLOBALS['agend_test_posts'][33] = array(
			'ID'           => 33,
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_content' => '<!-- wp:agend-apps/record-image {"use_term_images":true,"term_field":"listing:categories"} /-->',
		);

		$keys = ( new Agend_Apps_Block_Template_Renderer() )->field_keys( 33 );

		$this->assertContains( 'listing:categories', $keys['fields'] );
		$this->assertSame( 'categories', agend_apps_records_search_include_for_keys( $keys ) );
	}

	#[Test]
	public function should_need_all_custom_fields_when_a_block_template_has_a_custom_fields_panel(): void {
		$GLOBALS['agend_test_posts'][34] = array(
			'ID'           => 34,
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_content' => '<!-- wp:group --><div><!-- wp:agend-apps/record-field {"field":"common:custom_field","custom_field_key":"a"} /--><!-- wp:agend-apps/record-block {"block":"listing_custom_fields"} /--></div><!-- /wp:group -->',
		);

		$keys = ( new Agend_Apps_Block_Template_Renderer() )->field_keys( 34 );

		$this->assertSame( array( 'a' ), $keys['custom_fields'] );
		$this->assertTrue( $keys['all_custom_fields'] );
	}

	#[Test]
	public function should_own_no_keys_for_an_id_that_is_not_a_block_template(): void {
		$GLOBALS['agend_test_posts'][32] = array( 'ID' => 32, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' );

		$this->assertNull( ( new Agend_Apps_Block_Template_Renderer() )->field_keys( 32 ) );
	}

	// -- The registry ------------------------------------------------------------

	#[Test]
	public function should_report_no_keys_and_need_all_custom_fields_when_no_reader_owns_the_template(): void {
		$keys = Agend_Apps_Templates::field_keys( 7 );

		$this->assertSame( array(), $keys['fields'] );
		$this->assertSame( array(), $keys['custom_fields'] );
		$this->assertTrue( $keys['all_custom_fields'] );
	}

	#[Test]
	public function should_reuse_a_template_reading_until_the_template_is_modified(): void {
		$reader                         = $this->useTemplateBinding( 'listing:phone' );
		$GLOBALS['agend_test_posts'][7] = array( 'ID' => 7, 'post_modified_gmt' => '2026-10-01 00:00:00' );

		Agend_Apps_Templates::field_keys( 7 );
		Agend_Apps_Templates::field_keys( 7 );
		$this->assertSame( 1, $reader->reads );

		$GLOBALS['agend_test_posts'][7]['post_modified_gmt'] = '2026-10-02 00:00:00';
		Agend_Apps_Templates::field_keys( 7 );
		$this->assertSame( 2, $reader->reads );
	}

	#[Test]
	public function should_read_a_template_afresh_when_another_reader_registers_after_an_empty_reading(): void {
		$GLOBALS['agend_test_posts'][7] = array( 'ID' => 7, 'post_modified_gmt' => '2026-10-01 00:00:00' );
		$this->assertSame( array(), Agend_Apps_Templates::field_keys( 7 )['fields'] );

		$this->useTemplateBinding( 'listing:phone' );

		$this->assertSame( array( 'listing:phone' ), Agend_Apps_Templates::field_keys( 7 )['fields'] );
	}

	// -- The searches a catalogue makes -----------------------------------------

	#[Test]
	public function should_send_include_categories_on_the_first_page_render_when_the_card_template_binds_categories(): void {
		$this->useTemplateBinding( 'listing:categories' );

		agend_apps_records_render_directory_catalogue( array( 'card_template' => '7' ) );

		$this->assertSame( 'categories', $this->searches()[0]['include'] ?? null );
	}

	#[Test]
	public function should_send_include_categories_from_the_fragments_route_when_the_card_template_binds_categories(): void {
		$this->useTemplateBinding( 'listing:categories' );

		( new Agend_Apps_Records_Fragments_Controller() )->get_cards( $this->fragmentsRequest( array() ), 'listing' );

		$this->assertSame( 'categories', $this->searches()[0]['include'] ?? null );
	}

	#[Test]
	public function should_send_phone_in_include_when_the_card_template_binds_the_phone(): void {
		$this->useTemplateBinding( 'listing:phone', 'listing:categories' );

		agend_apps_records_render_directory_catalogue( array( 'card_template' => '7' ) );
		( new Agend_Apps_Records_Fragments_Controller() )->get_cards( $this->fragmentsRequest( array() ), 'listing' );

		$this->assertSame( 'categories,phone', $this->searches()[0]['include'] ?? null );
		$this->assertSame( 'categories,phone', $this->searches()[1]['include'] ?? null );
	}

	#[Test]
	public function should_send_no_include_when_the_card_template_binds_neither_categories_nor_phone(): void {
		$this->useTemplateBinding( 'listing:name', 'listing:location_name' );

		agend_apps_records_render_directory_catalogue( array( 'card_template' => '7' ) );
		( new Agend_Apps_Records_Fragments_Controller() )->get_cards( $this->fragmentsRequest( array() ), 'listing' );

		$this->assertArrayNotHasKey( 'include', $this->searches()[0] );
		$this->assertArrayNotHasKey( 'include', $this->searches()[1] );
	}

	#[Test]
	public function should_ignore_an_include_on_the_fragments_request_when_deriving_it_from_the_template(): void {
		$this->useTemplateBinding( 'listing:categories' );

		( new Agend_Apps_Records_Fragments_Controller() )->get_cards( $this->fragmentsRequest( array( 'include' => 'phone' ) ), 'listing' );

		$this->assertSame( 'categories', $this->searches()[0]['include'] ?? null );
	}

	#[Test]
	public function should_send_no_include_from_a_fragments_request_that_asks_for_one_when_the_template_binds_none(): void {
		$this->useTemplateBinding( 'listing:name' );

		( new Agend_Apps_Records_Fragments_Controller() )->get_cards( $this->fragmentsRequest( array( 'include' => 'phone,categories' ) ), 'listing' );

		$this->assertArrayNotHasKey( 'include', $this->searches()[0] );
	}

	#[Test]
	public function should_never_forward_include_or_fields_through_the_browser_search_proxy(): void {
		$reflection = new ReflectionMethod( Agend_Apps_Directory_REST_Controller::class, 'search_filters' );
		$reflection->setAccessible( true );

		$request = new WP_REST_Request( 'GET', '/agend-apps/v1/directory/search' );
		$request->set_param( 'include', 'phone' );
		$request->set_param( 'fields', 'custom.a' );
		$request->set_param( 'city', 'Sydney' );
		$filters = $reflection->invoke( new Agend_Apps_Directory_REST_Controller(), $request );

		$this->assertArrayNotHasKey( 'include', $filters );
		$this->assertArrayNotHasKey( 'fields', $filters );
		$this->assertSame( 'Sydney', $filters['city'] );
	}

	#[Test]
	public function should_send_exactly_the_bound_custom_fields_on_every_catalogue_search(): void {
		$this->useCustomFieldBinding( array( 'a', 'payment_options' ) );

		$searches = $this->catalogueSearches();

		$this->assertSame( 'custom.a,custom.payment_options', $searches[0]['fields'] ?? null );
		$this->assertSame( 'custom.a,custom.payment_options', $searches[1]['fields'] ?? null );
	}

	#[Test]
	public function should_send_an_empty_fields_value_when_the_card_template_shows_no_custom_field(): void {
		$this->useTemplateBinding( 'listing:name' );

		$searches = $this->catalogueSearches();

		$this->assertSame( '', $searches[0]['fields'] ?? null );
		$this->assertSame( '', $searches[1]['fields'] ?? null );
	}

	#[Test]
	public function should_send_no_fields_when_the_card_template_shows_every_custom_field(): void {
		$this->useCustomFieldBinding( array( 'a' ), true );

		$searches = $this->catalogueSearches();

		$this->assertArrayNotHasKey( 'fields', $searches[0] );
		$this->assertArrayNotHasKey( 'fields', $searches[1] );
	}

	#[Test]
	public function should_send_no_fields_when_no_reader_owns_the_card_template(): void {
		agend_apps_records_render_directory_catalogue( array( 'card_template' => '7' ) );

		$this->assertArrayNotHasKey( 'fields', $this->searches()[0] );
	}

	#[Test]
	public function should_ignore_fields_on_the_fragments_request_when_deriving_them_from_the_template(): void {
		$this->useCustomFieldBinding( array( 'a' ) );

		( new Agend_Apps_Records_Fragments_Controller() )->get_cards( $this->fragmentsRequest( array( 'fields' => 'custom.secret' ) ), 'listing' );

		$this->assertSame( 'custom.a', $this->searches()[0]['fields'] ?? null );
	}

	#[Test]
	public function should_send_the_saved_templates_custom_fields_when_the_template_is_modified(): void {
		$reader                         = $this->useCustomFieldBinding( array( 'a' ) );
		$GLOBALS['agend_test_posts'][7] = array( 'ID' => 7, 'post_modified_gmt' => '2026-10-01 00:00:00' );
		agend_apps_records_render_directory_catalogue( array( 'card_template' => '7' ) );

		$reader->custom_fields                               = array( 'b' );
		$GLOBALS['agend_test_posts'][7]['post_modified_gmt'] = '2026-10-02 00:00:00';
		agend_apps_records_render_directory_catalogue( array( 'card_template' => '7' ) );

		$this->assertSame( 'custom.a', $this->searches()[0]['fields'] ?? null );
		$this->assertSame( 'custom.b', $this->searches()[1]['fields'] ?? null );
	}
}
