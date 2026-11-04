<?php
/**
 * Content-settings schema for the jobs catalogue surface (Agend Careers).
 *
 * One surface covers the jobs board, its search, the job detail view and a
 * featured jobs strip: the featured-only toggle plus a small page size gives
 * the strip, and a dedicated Jobs page (Settings) sends its links to the board.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The option list below makes no gateway call for a key the surface gate has
// already ruled out.
require_once __DIR__ . '/../surface-gate.php';

/**
 * The jobs catalogue's content-settings schema.
 *
 * @return array
 */
function agend_apps_records_schema_jobs_catalogue(): array {
	return array(
		'sections' => array(
			array(
				'id'     => 'section_heading',
				'label'  => __( 'Heading', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_heading',
						'label'   => __( 'Show heading block', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'heading_text',
						'label'     => __( 'Heading', 'agend-apps-core' ),
						'type'      => 'text',
						'default'   => __( 'Careers', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
					array(
						'name'      => 'subheading_text',
						'label'     => __( 'Subheading', 'agend-apps-core' ),
						'type'      => 'textarea',
						'default'   => __( 'The latest roles from across the profession.', 'agend-apps-core' ),
						'condition' => array( 'show_heading' => 'yes' ),
					),
				),
			),
			array(
				'id'     => 'section_listing',
				'label'  => __( 'Listings', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'featured_only',
						'label'       => __( 'Featured jobs only', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Show only listings marked as featured, for example a strip on the home page. Set a Jobs page under Settings so these link to the full board.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'category',
						'label'       => __( 'Only this category', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => '',
						'label_block' => true,
						'options'     => 'agend_apps_records_jobs_catalogue_category_options',
						'description' => __( 'Limit the listings to one category. The visitor category filter is hidden while this is set.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'sort',
						'label'   => __( 'Order', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'published_at:desc',
						'options' => array(
							'published_at:desc' => __( 'Newest first', 'agend-apps-core' ),
							'expires_at:asc'    => __( 'Closing soonest', 'agend-apps-core' ),
							'title:asc'         => __( 'Title, A to Z', 'agend-apps-core' ),
							'salary_max:desc'   => __( 'Highest salary', 'agend-apps-core' ),
						),
					),
				),
			),
			array(
				'id'     => 'section_layout',
				'label'  => __( 'Layout', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'layout_style',
						'label'   => __( 'Layout', 'agend-apps-core' ),
						'type'    => 'select',
						'default' => 'list',
						'options' => array(
							'list' => __( 'List', 'agend-apps-core' ),
							'grid' => __( 'Grid', 'agend-apps-core' ),
						),
					),
					array(
						'name'      => 'columns_desktop',
						'label'     => __( 'Columns (Desktop)', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'2' => '2',
							'3' => '3',
							'4' => '4',
						),
						'default'   => '3',
						'condition' => array( 'layout_style' => 'grid' ),
					),
					array(
						'name'      => 'columns_tablet',
						'label'     => __( 'Columns (Tablet)', 'agend-apps-core' ),
						'type'      => 'select',
						'options'   => array(
							'1' => '1',
							'2' => '2',
						),
						'default'   => '2',
						'condition' => array( 'layout_style' => 'grid' ),
					),
					array(
						'name'    => 'card_radius',
						'label'   => __( 'Card Corner Radius (px)', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 10,
						'min'     => 0,
						'max'     => 48,
					),
				),
			),
			array(
				'id'     => 'section_card_fields',
				'label'  => __( 'Card Fields', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_logo',
						'label'   => __( 'Show employer logo', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_company',
						'label'   => __( 'Show employer name', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_location',
						'label'   => __( 'Show location', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_employment_type',
						'label'   => __( 'Show employment type', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_work_arrangement',
						'label'   => __( 'Show work arrangement (on site, hybrid, remote)', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'show_salary',
						'label'       => __( 'Show salary', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Only for listings whose poster chose to show it.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_categories',
						'label'   => __( 'Show categories', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_summary',
						'label'   => __( 'Show summary', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'      => 'excerpt_length',
						'label'     => __( 'Summary length (characters)', 'agend-apps-core' ),
						'type'      => 'number',
						'default'   => 160,
						'min'       => 20,
						'max'       => 400,
						'condition' => array( 'show_summary' => 'yes' ),
					),
					array(
						'name'    => 'show_posted_date',
						'label'   => __( 'Show date posted', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_closing_date',
						'label'   => __( 'Show closing date', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
				),
			),
			array(
				'id'     => 'section_filters',
				'label'  => __( 'Visitor Filter Bar', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'show_search',
						'label'   => __( 'Show keyword search', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_category_filter',
						'label'   => __( 'Show category filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_type_filter',
						'label'   => __( 'Show employment type filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'show_location_filter',
						'label'       => __( 'Show location search', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Matches the typed text against a listing\'s city, state or country.', 'agend-apps-core' ),
					),
				),
			),
			array(
				'id'     => 'section_detail',
				'label'  => __( 'Job Detail', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'apply_label',
						'label'   => __( 'Apply button text', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Apply now', 'agend-apps-core' ),
					),
					array(
						'name'        => 'allow_email_apply',
						'label'       => __( 'Offer apply by email', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'When a listing has no application link, show its application email address as the way to apply. Off: such a listing shows no apply button.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_company_panel',
						'label'   => __( 'Show "About the employer"', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
				),
			),
			array(
				'id'     => 'section_pagination',
				'label'  => __( 'Pagination', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'    => 'pagination_style',
						'label'   => __( 'Pagination style', 'agend-apps-core' ),
						'type'    => 'select',
						'options' => array(
							'numbered'  => __( 'Numbered pages', 'agend-apps-core' ),
							'load_more' => __( 'Load more button', 'agend-apps-core' ),
							'none'      => __( 'None (show all fetched)', 'agend-apps-core' ),
						),
						'default' => 'numbered',
					),
					array(
						'name'    => 'per_page',
						'label'   => __( 'Jobs per page', 'agend-apps-core' ),
						'type'    => 'number',
						'default' => 10,
						'min'     => 1,
						'max'     => 100,
					),
				),
			),
			array(
				'id'     => 'section_style_colours',
				'label'  => __( 'Colours', 'agend-apps-core' ),
				'tab'    => 'style',
				'fields' => array(
					array(
						'name'        => 'inherit_colours',
						'label'       => __( 'Inherit theme colours', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Use the site\'s theme colours when the theme sets them, otherwise the connected Agend account\'s colours. Turn off to set them manually below.', 'agend-apps-core' ),
					),
					array(
						'name'      => 'heading_colour',
						'label'     => __( 'Heading colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#1E2A4A',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'body_colour',
						'label'     => __( 'Body text colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#26304D',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'        => 'accent_colour',
						'label'       => __( 'Highlight / accent colour', 'agend-apps-core' ),
						'type'        => 'colour',
						'default'     => '#FF6B55',
						'description' => __( 'Drives the featured marker, category labels and the active filter state.', 'agend-apps-core' ),
						'condition'   => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'button_colour',
						'label'     => __( 'Button colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#FF6B55',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
					array(
						'name'      => 'button_text_colour',
						'label'     => __( 'Button text colour', 'agend-apps-core' ),
						'type'      => 'colour',
						'default'   => '#FFFFFF',
						'condition' => array( 'inherit_colours!' => 'yes' ),
					),
				),
			),
		),
	);
}

/**
 * Category options for the jobs catalogue's "Only this category" picker.
 *
 * The gateway has no public job categories endpoint, so the distinct set is
 * derived from a wide, cached list fetch, keyed by category slug (what the
 * list endpoint filters on). Empty outside an editor
 * {@see agend_apps_records_is_editor_request()}.
 *
 * @return array Options keyed by category slug, with an "all" entry first.
 */
function agend_apps_records_jobs_catalogue_category_options(): array {
	$options = array( '' => __( 'All categories', 'agend-apps-core' ) );

	if ( ! function_exists( 'agend_apps_jobs_get_jobs' ) || ! agend_apps_records_is_editor_request() || agend_apps_records_surface_gated( 'jobs-catalogue' ) ) {
		return $options;
	}

	$response = agend_apps_jobs_get_jobs( array( 'limit' => 100 ) );

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return $options;
	}

	foreach ( $response['data'] as $job ) {
		foreach ( (array) ( $job['categories'] ?? array() ) as $category ) {
			if ( ! empty( $category['slug'] ) ) {
				$options[ (string) $category['slug'] ] = (string) ( $category['name'] ?? $category['slug'] );
			}
		}
	}

	return $options;
}
