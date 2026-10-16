<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Elementor;

use Agend_Apps_Records_Filter_Context;
use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/filters.php';

/**
 * The filter registry and the config each Agend Filter widget hands to the
 * catalogue script.
 */
final class FilterConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Agend_Apps_Records_Filter_Context::reset();
	}

	#[Test]
	public function should_write_the_state_key_the_query_builder_reads(): void {
		$config = agend_apps_records_filter_config( 'event', 'category', array() );

		$this->assertSame( 'categories', $config['state'] );
		$this->assertSame( 'array', $config['mode'] );
	}

	#[Test]
	public function should_use_a_scalar_state_key_for_single_value_filters(): void {
		$course = agend_apps_records_filter_config( 'course', 'difficulty', array() );
		$rating = agend_apps_records_filter_config( 'listing', 'rating', array() );

		$this->assertSame( 'difficulty', $course['state'] );
		$this->assertSame( 'scalar', $course['mode'] );
		$this->assertSame( 'rating', $rating['state'] );
	}

	#[Test]
	public function should_resolve_static_values_server_side(): void {
		$config = agend_apps_records_filter_config( 'event', 'venue_type', array() );

		$labels = array_column( $config['values'], 'label' );
		$this->assertContains( 'In-Person', $labels );
		$this->assertSame( array( 'physical' ), $config['values'][0]['value'] );
		$this->assertNull( $config['source'] );
	}

	#[Test]
	public function should_name_the_endpoint_for_values_it_cannot_resolve_server_side(): void {
		$config = agend_apps_records_filter_config( 'listing', 'category', array() );

		$this->assertSame( '/directory/categories', $config['source']['path'] );
		$this->assertSame( 'id', $config['source']['value'] );
		$this->assertSame( array(), $config['values'] );
	}

	#[Test]
	public function should_send_every_value_of_an_author_defined_choice(): void {
		$config = agend_apps_records_filter_config(
			'event',
			'city',
			array(
				'values_mode' => 'choices',
				'choices'     => array(
					array( 'choice_label' => 'All States', 'choice_value' => 'NSW, VIC ,QLD' ),
					array( 'choice_label' => 'All Territories', 'choice_value' => 'ACT,NT' ),
				),
			)
		);

		$this->assertCount( 2, $config['values'] );
		$this->assertSame( 'All States', $config['values'][0]['label'] );
		$this->assertSame( array( 'NSW', 'VIC', 'QLD' ), $config['values'][0]['value'] );
		$this->assertSame( array( 'ACT', 'NT' ), $config['values'][1]['value'] );
		$this->assertNull( $config['source'], 'defined choices never fetch a value list' );
	}

	#[Test]
	public function should_drop_empty_choice_rows(): void {
		$config = agend_apps_records_filter_config(
			'event',
			'city',
			array(
				'values_mode' => 'choices',
				'choices'     => array(
					array( 'choice_label' => '', 'choice_value' => '' ),
					array( 'choice_label' => 'Sydney', 'choice_value' => 'Sydney' ),
				),
			)
		);

		$this->assertCount( 1, $config['values'] );
	}

	#[Test]
	public function should_fall_back_when_the_presentation_is_not_supported(): void {
		$search = agend_apps_records_filter_config( 'event', 'search', array( 'control' => 'buttons' ) );
		$chosen = agend_apps_records_filter_config( 'event', 'category', array( 'control' => 'buttons' ) );

		$this->assertSame( 'search', $search['control'], 'a search box has no other presentation' );
		$this->assertSame( 'buttons', $chosen['control'] );
	}

	#[Test]
	public function should_return_null_for_a_filter_the_type_does_not_offer(): void {
		$this->assertNull( agend_apps_records_filter_config( 'course', 'city', array() ) );
		$this->assertNull( agend_apps_records_filter_descriptor( 'listing', 'difficulty' ) );
	}

	#[Test]
	public function should_fall_back_to_the_filters_own_name_when_the_label_is_blank(): void {
		$blank    = agend_apps_records_filter_config( 'event', 'category', array( 'label' => '' ) );
		$spaces   = agend_apps_records_filter_config( 'event', 'category', array( 'label' => '   ' ) );
		$explicit = agend_apps_records_filter_config( 'event', 'category', array( 'label' => 'Topic' ) );

		$this->assertSame( 'Category', $blank['label'], 'an unset Elementor text control is an empty string' );
		$this->assertSame( 'Category', $spaces['label'] );
		$this->assertSame( 'Topic', $explicit['label'] );
	}

	#[Test]
	public function should_group_picker_options_by_catalogue(): void {
		$groups = agend_apps_records_filter_options();
		$labels = array_column( $groups, 'label' );

		$this->assertSame( array( 'Events', 'Courses', 'Directory' ), $labels );
		$this->assertArrayHasKey( 'event:category', $groups[0]['options'] );
		$this->assertArrayHasKey( 'listing:rating', $groups[2]['options'] );
	}

	#[Test]
	public function should_hold_the_record_type_for_the_template_render_in_progress(): void {
		$this->assertSame( '', Agend_Apps_Records_Filter_Context::type() );

		Agend_Apps_Records_Filter_Context::set( 'listing' );
		$this->assertSame( 'listing', Agend_Apps_Records_Filter_Context::type() );

		Agend_Apps_Records_Filter_Context::reset();
		$this->assertSame( '', Agend_Apps_Records_Filter_Context::type() );
	}

	#[Test]
	public function should_name_the_facet_for_tag_and_badge_values(): void {
		$tag   = agend_apps_records_filter_config( 'listing', 'tag', array() );
		$badge = agend_apps_records_filter_config( 'listing', 'badge', array() );

		$this->assertSame( 'tags', $tag['source']['facet'] );
		$this->assertSame( 'tag_ids', $tag['state'] );
		$this->assertSame( 'badges', $badge['source']['facet'] );
		$this->assertSame( 'badge_ids', $badge['state'] );
	}

	#[Test]
	public function should_still_honour_defined_choices_over_a_facet(): void {
		$config = agend_apps_records_filter_config(
			'listing',
			'tag',
			array(
				'values_mode' => 'choices',
				'choices'     => array( array( 'choice_label' => 'Sponsor', 'choice_value' => 'tag-uuid-1,tag-uuid-2' ) ),
			)
		);

		$this->assertNull( $config['source'], 'defined choices never fetch a value list' );
		$this->assertSame( array( 'tag-uuid-1', 'tag-uuid-2' ), $config['values'][0]['value'] );
	}

	#[Test]
	public function should_address_a_custom_field_facet_by_its_key(): void {
		$config = agend_apps_records_filter_config( 'listing', 'custom_field', array( 'custom_field_key' => 'education_level' ) );

		$this->assertSame( 'custom.education_level', $config['source']['facet'] );
		$this->assertSame( 'custom_fields', $config['state'] );
		$this->assertSame( 'map', $config['mode'] );
		$this->assertSame( 'education_level', $config['fieldKey'] );
	}

	#[Test]
	public function should_render_nothing_for_a_custom_field_filter_with_no_key(): void {
		$this->assertNull( agend_apps_records_filter_config( 'listing', 'custom_field', array( 'custom_field_key' => '  ' ) ) );
	}

	#[Test]
	public function should_offer_a_featured_only_toggle_for_listings(): void {
		$config = agend_apps_records_filter_config( 'listing', 'featured', array() );

		$this->assertSame( 'featured', $config['state'] );
		$this->assertSame( array( array( 'label' => 'Featured only', 'value' => array( '1' ) ) ), $config['values'] );
	}

	#[Test]
	public function should_offer_a_clear_button_on_every_catalogue(): void {
		foreach ( array( 'event', 'course', 'listing' ) as $type ) {
			$config = agend_apps_records_filter_config( $type, 'reset', array() );
			$this->assertSame( 'reset', $config['control'], $type );
			$this->assertSame( '', $config['state'], $type );
		}
	}

	#[Test]
	public function should_offer_sort_for_the_directory_only(): void {
		$this->assertNotNull( agend_apps_records_filter_descriptor( 'listing', 'sort' ) );
		$this->assertNull( agend_apps_records_filter_descriptor( 'event', 'sort' ), 'the events list endpoint has no sort parameter' );
		$this->assertNull( agend_apps_records_filter_descriptor( 'course', 'sort' ) );

		$config = agend_apps_records_filter_config( 'listing', 'sort', array() );
		$this->assertSame( 'sortBy', $config['state'] );
		$this->assertContains( 'Highest rated', array_column( $config['values'], 'label' ) );
	}

	#[Test]
	public function should_name_the_location_facet_for_each_directory_location_filter(): void {
		$cases = array(
			'city'     => array( 'location_city', 'location.city' ),
			'state'    => array( 'location_state', 'location.state' ),
			'postcode' => array( 'location_postcode', 'location.postcode' ),
			'country'  => array( 'location_country', 'location.country' ),
		);

		foreach ( $cases as $key => list( $state, $facet ) ) {
			$config = agend_apps_records_filter_config( 'listing', $key, array() );

			$this->assertSame( $state, $config['state'], $key );
			$this->assertSame( 'array', $config['mode'], $key );
			$this->assertSame( $facet, $config['source']['facet'], $key );
		}
	}

	#[Test]
	public function should_offer_all_four_location_filters_under_the_directory_group(): void {
		$groups    = agend_apps_records_filter_options();
		$directory = $groups[2]['options'];

		$this->assertArrayHasKey( 'listing:city', $directory );
		$this->assertArrayHasKey( 'listing:state', $directory );
		$this->assertArrayHasKey( 'listing:postcode', $directory );
		$this->assertArrayHasKey( 'listing:country', $directory );
	}

	#[Test]
	public function should_offer_organisation_with_the_search_box_as_its_default_when_an_author_picks_a_directory_filter(): void {
		$groups    = agend_apps_records_filter_options();
		$directory = $groups[2]['options'];

		$this->assertSame( 'Organisation', $directory['listing:organisation'] );

		$descriptor = agend_apps_records_filter_descriptor( 'listing', 'organisation' );
		$this->assertSame( 'location_name', $descriptor['state'] );
		$this->assertSame( 'scalar', $descriptor['mode'] );
		$this->assertSame( array( 'search' ), $descriptor['controls'] );
		$this->assertNull( $descriptor['source'] );

		$config = agend_apps_records_filter_config( 'listing', 'organisation', array() );
		$this->assertSame( 'search', $config['control'] );
		$this->assertSame( 'location_name', $config['state'] );
		$this->assertSame( 'scalar', $config['mode'] );
		$this->assertSame( 'Organisation', $config['label'] );
	}

	#[Test]
	public function should_offer_organisation_only_when_the_catalogue_is_the_directory(): void {
		$this->assertNull( agend_apps_records_filter_descriptor( 'event', 'organisation' ) );
		$this->assertNull( agend_apps_records_filter_descriptor( 'course', 'organisation' ) );
	}

	#[Test]
	public function should_not_offer_a_suburb_filter_because_the_api_treats_it_as_a_city_alias(): void {
		$this->assertNull( agend_apps_records_filter_descriptor( 'listing', 'suburb' ) );
	}

	#[Test]
	public function should_mark_course_categories_approximate_because_they_cannot_be_enumerated(): void {
		$descriptor = agend_apps_records_filter_descriptor( 'course', 'category' );

		$this->assertTrue( $descriptor['approximate'] );
		$this->assertSame( 100, $descriptor['source']['limit'] );
	}

	// -- Category level --

	/**
	 * @return array<string, mixed> The filter schema's category_level field.
	 */
	private function categoryLevelField(): array {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema.php';

		foreach ( agend_apps_records_surface_schema( 'filter' )['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				if ( 'category_level' === $field['name'] ) {
					return $field;
				}
			}
		}
		$this->fail( 'the filter schema has no category_level field' );
	}

	#[Test]
	public function should_offer_a_category_level_setting_defaulting_to_all_categories_on_the_directory_category_filter(): void {
		$field = $this->categoryLevelField();

		$this->assertSame( 'Category level', $field['label'] );
		$this->assertSame( 'select', $field['type'] );
		$this->assertSame( 'all', $field['default'] );
		$this->assertSame(
			array(
				'all' => 'All categories',
				'top' => 'Top level',
				'sub' => 'Subcategories of selected',
			),
			$field['options']
		);
		$this->assertSame( array( 'filter' => 'listing:category' ), $field['condition'], 'shown only for the directory Category filter' );
	}

	#[Test]
	public function should_hand_the_runtime_all_categories_when_the_level_is_unset_or_unknown(): void {
		$unset   = agend_apps_records_filter_config( 'listing', 'category', array() );
		$unknown = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => 'grandchildren' ) );

		$this->assertSame( 'all', $unset['categoryLevel'] );
		$this->assertSame( 'all', $unknown['categoryLevel'] );
		$this->assertSame( 'checkboxes', $unset['control'], 'All categories keeps the filter\'s own default presentation' );
	}

	#[Test]
	public function should_hand_the_runtime_the_chosen_level_when_it_is_top_level_or_subcategories(): void {
		$top = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => 'top' ) );
		$sub = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => 'sub' ) );

		$this->assertSame( 'top', $top['categoryLevel'] );
		$this->assertSame( 'sub', $sub['categoryLevel'] );
		$this->assertSame( 'categories', $top['state'], 'both levels write the one shared selection' );
		$this->assertSame( 'categories', $sub['state'] );
		$this->assertSame( 'array', $sub['mode'] );
	}

	#[Test]
	public function should_offer_only_the_select_and_buttons_controls_when_the_level_is_top_level_or_subcategories(): void {
		foreach ( array( 'top', 'sub' ) as $level ) {
			$checkboxes = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => $level, 'control' => 'checkboxes' ) );
			$default    = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => $level ) );
			$buttons    = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => $level, 'control' => 'buttons' ) );
			$select     = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => $level, 'control' => 'select' ) );

			$this->assertSame( 'select', $checkboxes['control'], $level . ': checkboxes are never offered' );
			$this->assertSame( 'select', $default['control'], $level . ': the default is a dropdown, not checkboxes' );
			$this->assertSame( 'buttons', $buttons['control'], $level );
			$this->assertSame( 'select', $select['control'], $level );
		}

		$all = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => 'all', 'control' => 'checkboxes' ) );
		$this->assertSame( 'checkboxes', $all['control'], 'All categories still offers checkboxes' );
	}

	#[Test]
	public function should_keep_parent_id_in_the_directory_category_source_so_the_runtime_has_the_tree(): void {
		$descriptor = agend_apps_records_filter_descriptor( 'listing', 'category' );
		$this->assertSame( 'parent_id', $descriptor['source']['parent'] );

		foreach ( array( 'all', 'top', 'sub' ) as $level ) {
			$config = agend_apps_records_filter_config( 'listing', 'category', array( 'category_level' => $level ) );

			$this->assertSame(
				array(
					'path'      => '/directory/categories',
					'value'     => 'id',
					'labelKey'  => 'name',
					'distinct'  => false,
					'limit'     => 0,
					'parentKey' => 'parent_id',
				),
				$config['source'],
				$level
			);
		}
	}

	#[Test]
	public function should_ignore_the_level_when_the_author_defines_the_choices(): void {
		$config = agend_apps_records_filter_config(
			'listing',
			'category',
			array(
				'category_level' => 'top',
				'control'        => 'checkboxes',
				'values_mode'    => 'choices',
				'choices'        => array( array( 'choice_label' => 'Paediatrics', 'choice_value' => 'c1,c2' ) ),
			)
		);

		$this->assertSame( 'all', $config['categoryLevel'] );
		$this->assertSame( 'checkboxes', $config['control'] );
		$this->assertSame( array( 'c1', 'c2' ), $config['values'][0]['value'] );
	}

	#[Test]
	public function should_give_no_category_level_to_a_filter_without_a_category_tree(): void {
		$event = agend_apps_records_filter_config( 'event', 'category', array( 'category_level' => 'top' ) );
		$state = agend_apps_records_filter_config( 'listing', 'state', array( 'category_level' => 'top', 'control' => 'checkboxes' ) );

		$this->assertArrayNotHasKey( 'categoryLevel', $event );
		$this->assertArrayNotHasKey( 'parentKey', $event['source'] );
		$this->assertArrayNotHasKey( 'categoryLevel', $state );
		$this->assertSame( 'checkboxes', $state['control'] );
	}
}
