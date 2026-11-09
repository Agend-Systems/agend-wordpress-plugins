<?php
/**
 * @package Agend\Tests
 */

declare( strict_types=1 );

namespace Agend\Tests\Core;

use Agend\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The jobs catalogue's client config, built from the surface settings in
 * either editor's shape. One surface is both the full board and a featured
 * strip, so the settings that switch between the two are pinned here.
 */
final class JobsCatalogueConfigTest extends TestCase {

	/**
	 * Loads only the config builder; it needs no gateway (see
	 * MembershipsCatalogueUrlShapeTest for why the render doubles stay out).
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/palette.php';
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/render/jobs-catalogue.php';
	}

	#[Test]
	public function should_default_to_a_full_board_in_a_single_column_list(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array() );

		self::assertFalse( $config['listing']['featuredOnly'] );
		self::assertSame( '', $config['listing']['category'] );
		self::assertSame( 'published_at', $config['listing']['sortBy'] );
		self::assertSame( 'desc', $config['listing']['sortOrder'] );
		self::assertSame( 'list', $config['layout']['style'] );
		self::assertSame( 1, $config['layout']['desktop'] );
		self::assertTrue( $config['filters']['search'] );
		self::assertTrue( $config['filters']['category'] );
		self::assertTrue( $config['filters']['type'] );
		self::assertTrue( $config['filters']['location'] );
		self::assertSame( 10, $config['pagination']['perPage'] );
		self::assertSame( 'Apply now', $config['detail']['applyLabel'] );
		self::assertTrue( $config['detail']['emailApply'] );
	}

	#[Test]
	public function should_show_only_featured_jobs_when_the_featured_toggle_is_on(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'featured_only' => 'yes' ) );

		self::assertTrue( $config['listing']['featuredOnly'] );
	}

	#[Test]
	public function should_hide_the_visitor_category_filter_when_the_board_is_pinned_to_one_category(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'category'             => 'Financial Planning',
				'show_category_filter' => 'yes',
			)
		);

		self::assertSame( 'financial-planning', $config['listing']['category'] );
		self::assertFalse( $config['filters']['category'] );
	}

	#[Test]
	public function should_map_each_offered_order_to_a_gateway_sort(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'sort' => 'expires_at:asc' ) );

		self::assertSame( 'expires_at', $config['listing']['sortBy'] );
		self::assertSame( 'asc', $config['listing']['sortOrder'] );
	}

	#[Test]
	public function should_fall_back_to_newest_first_for_an_order_it_does_not_offer(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'sort' => 'view_count:desc' ) );

		self::assertSame( 'published_at', $config['listing']['sortBy'] );
		self::assertSame( 'desc', $config['listing']['sortOrder'] );
	}

	#[Test]
	public function should_use_the_column_settings_only_in_the_grid_layout(): void {
		$grid = agend_apps_records_jobs_catalogue_build_config(
			array(
				'layout_style'    => 'grid',
				'columns_desktop' => '4',
				'columns_tablet'  => '2',
			)
		);
		$list = agend_apps_records_jobs_catalogue_build_config(
			array(
				'layout_style'    => 'list',
				'columns_desktop' => '4',
			)
		);

		self::assertSame( 4, $grid['layout']['desktop'] );
		self::assertSame( 2, $grid['layout']['tablet'] );
		self::assertSame( 1, $list['layout']['desktop'] );
	}

	#[Test]
	public function should_keep_the_page_size_within_what_the_gateway_accepts(): void {
		self::assertSame( 100, agend_apps_records_jobs_catalogue_build_config( array( 'per_page' => 500 ) )['pagination']['perPage'] );
		self::assertSame( 1, agend_apps_records_jobs_catalogue_build_config( array( 'per_page' => 0 ) )['pagination']['perPage'] );
	}

	#[Test]
	public function should_fall_back_to_the_default_apply_label_when_it_is_blank(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'apply_label' => '   ' ) );

		self::assertSame( 'Apply now', $config['detail']['applyLabel'] );
	}

	#[Test]
	public function should_offer_the_experience_level_on_cards_and_as_a_filter_by_default(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array() );

		self::assertTrue( $config['card']['experience'] );
		self::assertTrue( $config['filters']['experience'] );
	}

	#[Test]
	public function should_fall_back_to_the_default_sign_in_wording_when_it_is_blank(): void {
		$default = agend_apps_records_jobs_catalogue_build_config( array( 'signin_text' => '  ', 'signin_button' => '' ) );
		$custom  = agend_apps_records_jobs_catalogue_build_config( array( 'signin_text' => 'Members, sign in.', 'signin_button' => 'Log in' ) );

		self::assertSame( 'Sign in as a member to see the full job details.', $default['signIn']['text'] );
		self::assertSame( 'Sign in', $default['signIn']['button'] );
		self::assertSame( 'Members, sign in.', $custom['signIn']['text'] );
		self::assertSame( 'Log in', $custom['signIn']['button'] );
	}

	#[Test]
	public function should_read_block_toggles_stored_as_yes_or_empty(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'show_salary'       => '',
				'allow_email_apply' => '',
			)
		);

		self::assertFalse( $config['card']['salary'] );
		self::assertFalse( $config['detail']['emailApply'] );
	}

	#[Test]
	public function should_put_featured_jobs_first_and_offer_work_arrangement_by_default(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array() );

		self::assertTrue( $config['listing']['featuredFirst'] );
		self::assertTrue( $config['filters']['workArrangement'] );
		self::assertFalse( $config['filters']['multiple'] );
		self::assertFalse( $config['filters']['state'] );
		self::assertFalse( $config['filters']['posted'] );
		self::assertSame( array(), $config['filters']['tags'] );
	}

	#[Test]
	public function should_offer_the_australian_states_by_default_when_the_state_filter_is_on(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'show_state_filter' => 'yes' ) );

		self::assertTrue( $config['filters']['state'] );
		self::assertSame( array( 'ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA' ), $config['filters']['states'] );
	}

	#[Test]
	public function should_read_the_states_offered_dropping_blanks_and_repeats(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'show_state_filter' => 'yes',
				'state_options'     => ' NSW, ,Victoria, nsw ,<b>QLD</b>',
			)
		);

		self::assertSame( array( 'NSW', 'Victoria', 'QLD' ), $config['filters']['states'] );
	}

	#[Test]
	public function should_hide_the_state_filter_when_no_state_is_offered(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'show_state_filter' => 'yes',
				'state_options'     => ' , ',
			)
		);

		self::assertFalse( $config['filters']['state'] );
	}

	#[Test]
	public function should_build_one_tag_filter_per_row_that_names_a_tag(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'tag_filters' => array(
					array(
						'label' => 'Salary band',
						'tags'  => array( 'salary-under-80k', 'salary-80k-120k', 'salary-120k-plus' ),
						'style' => 'select',
					),
					array(
						'label' => '',
						'tags'  => array( 'Accreditation Required' ),
						'style' => 'checkboxes',
					),
					array(
						'label' => 'Nothing picked',
						'tags'  => array(),
					),
					array(
						'label' => 'Odd style',
						'tags'  => array( 'remote-friendly' ),
						'style' => 'radio',
					),
				),
			)
		);

		self::assertSame(
			array(
				array(
					'label' => 'Salary band',
					'style' => 'select',
					'tags'  => array( 'salary-under-80k', 'salary-80k-120k', 'salary-120k-plus' ),
				),
				array(
					'label' => 'Tags',
					'style' => 'checkboxes',
					'tags'  => array( 'accreditation-required' ),
				),
				array(
					'label' => 'Odd style',
					'style' => 'select',
					'tags'  => array( 'remote-friendly' ),
				),
			),
			$config['filters']['tags']
		);
	}

	#[Test]
	public function should_let_visitors_pick_several_only_when_switched_on(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'allow_multiple' => 'yes' ) );

		self::assertTrue( $config['filters']['multiple'] );
	}
	#[Test]
	public function should_leave_the_distance_search_and_sort_off_by_default(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array() );

		self::assertFalse( $config['filters']['distance'] );
		self::assertFalse( $config['filters']['sort'] );
		self::assertSame( array( 10.0, 25.0, 50.0, 100.0, 250.0 ), $config['filters']['distanceRadii'] );
		self::assertSame( 50.0, $config['filters']['distanceRadius'] );
		self::assertSame( 'published_at:desc', $config['listing']['sort'] );
	}

	#[Test]
	public function should_switch_the_distance_search_and_sort_on_from_the_toggles(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'show_distance_filter' => 'yes',
				'show_sort'            => 'yes',
			)
		);

		self::assertTrue( $config['filters']['distance'] );
		self::assertTrue( $config['filters']['sort'] );
	}

	#[Test]
	public function should_read_the_radius_choices_the_way_the_directory_location_filter_does(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'show_distance_filter'    => 'yes',
				'distance_radius_choices' => '100, 5, abc, 5, 2000, -3, 25',
				'distance_radius_default' => '20',
			)
		);

		// Sorted, de-duplicated, positive and within the gateway's 1000 km.
		self::assertSame( array( 5.0, 25.0, 100.0 ), $config['filters']['distanceRadii'] );
		// A default that is not a choice snaps to the nearest one.
		self::assertSame( 25.0, $config['filters']['distanceRadius'] );
	}

	#[Test]
	public function should_pass_the_country_for_typed_places_only_when_it_is_set(): void {
		self::assertSame( '', agend_apps_records_jobs_catalogue_build_config( array() )['filters']['placeCountry'] );

		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'show_distance_filter'   => 'yes',
				'distance_place_country' => '  Australia <b>',
			)
		);
		self::assertSame( 'Australia', $config['filters']['placeCountry'] );

		// Longer than any country name: ignored rather than cut short.
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'distance_place_country' => str_repeat( 'x', 61 ) ) );
		self::assertSame( '', $config['filters']['placeCountry'] );

		// Whitespace only is the same as blank.
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'distance_place_country' => "   \t " ) );
		self::assertSame( '', $config['filters']['placeCountry'] );

		// The limit counts characters, not bytes: 40 three-byte characters fit.
		$name   = str_repeat( 'ニ', 40 );
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'distance_place_country' => $name ) );
		self::assertSame( $name, $config['filters']['placeCountry'] );
	}

	#[Test]
	public function should_fall_back_to_the_directory_radius_choices_when_none_are_usable(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'distance_radius_choices' => 'nope' ) );

		self::assertSame( array( 5.0, 10.0, 15.0, 20.0, 25.0, 50.0, 100.0 ), $config['filters']['distanceRadii'] );
	}

	#[Test]
	public function should_offer_the_visitor_sorts_but_not_nearest_which_needs_a_location(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array( 'show_sort' => 'yes' ) );
		$values = array_column( $config['filters']['sortOptions'], 'value' );

		self::assertSame( array( 'published_at:desc', 'expires_at:asc', 'title:asc' ), $values );
		self::assertNotContains( 'distance:asc', $values );
	}

	#[Test]
	public function should_open_the_sort_dropdown_on_the_boards_own_order(): void {
		$config  = agend_apps_records_jobs_catalogue_build_config( array( 'show_sort' => 'yes', 'sort' => 'salary_max:desc' ) );
		$options = $config['filters']['sortOptions'];

		self::assertSame( 'salary_max:desc', $options[0]['value'] );
		self::assertSame( 'Highest salary', $options[0]['label'] );
		self::assertCount( 4, $options );
		self::assertSame( 'salary_max:desc', $config['listing']['sort'] );

		$closing = agend_apps_records_jobs_catalogue_build_config( array( 'show_sort' => 'yes', 'sort' => 'expires_at:asc' ) );
		self::assertSame( array( 'expires_at:asc', 'published_at:desc', 'title:asc' ), array_column( $closing['filters']['sortOptions'], 'value' ) );
	}

	#[Test]
	public function should_declare_the_new_settings_off_by_default_in_the_schema(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/jobs-catalogue.php';

		$fields = array();
		foreach ( agend_apps_records_schema_jobs_catalogue()['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		self::assertFalse( $fields['show_distance_filter']['default'] );
		self::assertFalse( $fields['show_sort']['default'] );
		self::assertSame( '10, 25, 50, 100, 250', $fields['distance_radius_choices']['default'] );
		self::assertSame( 50, $fields['distance_radius_default']['default'] );
		self::assertSame( array( 'show_distance_filter' => 'yes' ), $fields['distance_radius_choices']['condition'] );
		self::assertSame( '', $fields['distance_place_country']['default'] );
		self::assertSame( array( 'show_distance_filter' => 'yes' ), $fields['distance_place_country']['condition'] );
		// The board's existing order setting stays the default order.
		self::assertSame( 'published_at:desc', $fields['sort']['default'] );
	}

	#[Test]
	public function should_keep_the_filter_bar_and_standard_job_page_by_default(): void {
		$config = agend_apps_records_jobs_catalogue_build_config( array() );

		self::assertSame( 'bar', $config['filters']['layout'] );
		self::assertSame( 'standard', $config['detail']['layout'] );
		self::assertSame( '', $config['colours']['jobHeader'] );
		self::assertSame( '', agend_apps_records_jobs_catalogue_layout_classes( $config ) );
		self::assertStringNotContainsString( '--agend-jobs-header', agend_apps_records_jobs_catalogue_inline_style( $config ) );
	}

	#[Test]
	public function should_switch_the_sidebar_and_header_layouts_on_from_their_settings(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'filter_layout' => 'sidebar',
				'detail_layout' => 'header',
			)
		);

		self::assertSame( 'sidebar', $config['filters']['layout'] );
		self::assertSame( 'header', $config['detail']['layout'] );
		self::assertSame(
			' agend-jobs-catalogue--filters-sidebar agend-jobs-catalogue--detail-header',
			agend_apps_records_jobs_catalogue_layout_classes( $config )
		);
	}

	#[Test]
	public function should_ignore_a_layout_value_it_does_not_know(): void {
		$config = agend_apps_records_jobs_catalogue_build_config(
			array(
				'filter_layout' => 'columns',
				'detail_layout' => 'sidebar',
			)
		);

		self::assertSame( 'bar', $config['filters']['layout'] );
		self::assertSame( 'standard', $config['detail']['layout'] );
	}

	#[Test]
	public function should_use_the_header_colour_only_with_the_header_layout(): void {
		$header = agend_apps_records_jobs_catalogue_build_config(
			array(
				'detail_layout'     => 'header',
				'job_header_colour' => '#0C5998',
			)
		);
		self::assertSame( '#0C5998', $header['colours']['jobHeader'] );
		self::assertStringContainsString( '--agend-jobs-header:#0C5998;', agend_apps_records_jobs_catalogue_inline_style( $header ) );

		// Blank follows the heading colour: nothing is set, so the stylesheet falls back.
		$blank = agend_apps_records_jobs_catalogue_build_config( array( 'detail_layout' => 'header' ) );
		self::assertSame( '', $blank['colours']['jobHeader'] );
		self::assertStringNotContainsString( '--agend-jobs-header', agend_apps_records_jobs_catalogue_inline_style( $blank ) );

		// A colour left over from the header layout does nothing on the standard one.
		$standard = agend_apps_records_jobs_catalogue_build_config( array( 'job_header_colour' => '#0C5998' ) );
		self::assertSame( '', $standard['colours']['jobHeader'] );

		// An unsafe value is dropped.
		$unsafe = agend_apps_records_jobs_catalogue_build_config(
			array(
				'detail_layout'     => 'header',
				'job_header_colour' => 'red;background:url(x)',
			)
		);
		self::assertSame( '', $unsafe['colours']['jobHeader'] );
	}

	#[Test]
	public function should_declare_the_layout_settings_opt_in_in_the_schema(): void {
		require_once AGEND_TESTS_ROOT . '/agend-apps-core/includes/records/schema/jobs-catalogue.php';

		$fields = array();
		foreach ( agend_apps_records_schema_jobs_catalogue()['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		self::assertSame( 'bar', $fields['filter_layout']['default'] );
		self::assertSame( array( 'bar', 'sidebar' ), array_keys( $fields['filter_layout']['options'] ) );
		self::assertSame( 'standard', $fields['detail_layout']['default'] );
		self::assertSame( array( 'standard', 'header' ), array_keys( $fields['detail_layout']['options'] ) );
		self::assertSame( '', $fields['job_header_colour']['default'] );
		self::assertSame( 'colour', $fields['job_header_colour']['type'] );
		self::assertSame( array( 'detail_layout' => 'header' ), $fields['job_header_colour']['condition'] );
	}
}
