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
					array(
						'name'        => 'featured_first',
						'label'       => __( 'Featured jobs first', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Puts sponsored and featured listings ahead of the order above.', 'agend-apps-core' ),
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
						'name'    => 'show_experience',
						'label'   => __( 'Show experience level', 'agend-apps-core' ),
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
						'name'        => 'filter_layout',
						'label'       => __( 'Filter layout', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'bar',
						'options'     => array(
							'bar'     => __( 'Filter bar: a row of dropdowns above the results', 'agend-apps-core' ),
							'sidebar' => __( 'Filter sidebar: groups of tick boxes beside the results', 'agend-apps-core' ),
						),
						'description' => __( 'The sidebar shows each filter you switch on below as a group on the left, with removable chips for what is applied and a Filters button on a phone. Category, employment type, work arrangement and experience level become tick boxes. Results show as a list of cards.', 'agend-apps-core' ),
					),
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
						'name'    => 'show_experience_filter',
						'label'   => __( 'Show experience level filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'    => 'show_work_arrangement_filter',
						'label'   => __( 'Show work arrangement filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => true,
					),
					array(
						'name'        => 'allow_multiple',
						'label'       => __( 'Let visitors pick several', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'The category, employment type, work arrangement and experience level filters become checkbox lists. A job matching any ticked option shows.', 'agend-apps-core' ),
					),
					array(
						'name'    => 'show_state_filter',
						'label'   => __( 'Show state filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => false,
					),
					array(
						'name'        => 'state_options',
						'label'       => __( 'States offered', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => 'ACT, NSW, NT, QLD, SA, TAS, VIC, WA',
						'label_block' => true,
						'description' => __( 'Separate with commas. Each must match the state as written on the listings.', 'agend-apps-core' ),
						'condition'   => array( 'show_state_filter' => 'yes' ),
					),
					array(
						'name'    => 'show_posted_filter',
						'label'   => __( 'Show date posted filter', 'agend-apps-core' ),
						'type'    => 'toggle',
						'default' => false,
					),
					array(
						'name'        => 'show_location_filter',
						'label'       => __( 'Show location search', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => true,
						'description' => __( 'Matches the typed text against a listing\'s city, state, country or postcode.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'show_distance_filter',
						'label'       => __( 'Show distance search', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Lets a visitor type a suburb or postcode, or use their current location, and choose a radius. Only jobs within it show, each with its distance. Typed places need place search on the Agend account; "Use my location" does not. Needs the gateway release that adds lat, lng and radius to the jobs list.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'distance_radius_choices',
						'label'       => __( 'Radius choices (km)', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '10, 25, 50, 100, 250',
						'label_block' => true,
						'description' => __( 'Separate with commas, up to 1000. A single value fixes the radius and hides the dropdown.', 'agend-apps-core' ),
						'condition'   => array( 'show_distance_filter' => 'yes' ),
					),
					array(
						'name'      => 'distance_radius_default',
						'label'     => __( 'Default radius (km)', 'agend-apps-core' ),
						'type'      => 'number',
						'default'   => 50,
						'min'       => 1,
						'max'       => 1000,
						'step'      => 1,
						'condition' => array( 'show_distance_filter' => 'yes' ),
					),
					array(
						'name'        => 'distance_place_country',
						'label'       => __( 'Region', 'agend-apps-core' ),
						'type'        => 'text',
						'default'     => '',
						'label_block' => true,
						'description' => __( 'Added to every typed place before it is looked up, like the Directory location filter\'s Region, so a bare postcode such as "5000" finds the place in this country rather than somewhere abroad. For example: Australia. Unlike the Directory, text that already names the region is sent as typed. Up to 60 characters; leave blank to look the text up as typed.', 'agend-apps-core' ),
						'condition'   => array( 'show_distance_filter' => 'yes' ),
					),
					array(
						'name'        => 'show_sort',
						'label'       => __( 'Show sort dropdown', 'agend-apps-core' ),
						'type'        => 'toggle',
						'default'     => false,
						'description' => __( 'Lets a visitor reorder the board: newest, closing soonest, title, and nearest once a location is set. The Order setting under Listings is the order the board opens in.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'tag_filters',
						'label'       => __( 'Tag filters', 'agend-apps-core' ),
						'type'        => 'repeater',
						'row_label'   => 'label',
						'default'     => array(),
						'description' => __( 'Each row is its own filter built from tags, for example a "Salary band" dropdown or an "Accreditation required" checkbox. A job must match every filter the visitor sets.', 'agend-apps-core' ),
						'fields'      => array(
							array(
								'name'    => 'label',
								'label'   => __( 'Label', 'agend-apps-core' ),
								'type'    => 'text',
								'default' => '',
							),
							array(
								'name'        => 'tags',
								'label'       => __( 'Tags', 'agend-apps-core' ),
								'type'        => 'multiselect',
								'label_block' => true,
								'options'     => 'agend_apps_records_jobs_catalogue_tag_options',
							),
							array(
								'name'        => 'style',
								'label'       => __( 'Shown as', 'agend-apps-core' ),
								'type'        => 'select',
								'default'     => 'select',
								'options'     => array(
									'select'     => __( 'Dropdown: pick one of the tags', 'agend-apps-core' ),
									'checkboxes' => __( 'Checkboxes: each ticked tag must match', 'agend-apps-core' ),
								),
							),
						),
					),
				),
			),
			array(
				'id'     => 'section_detail',
				'label'  => __( 'Job Detail', 'agend-apps-core' ),
				'fields' => array(
					array(
						'name'        => 'detail_layout',
						'label'       => __( 'Job page layout', 'agend-apps-core' ),
						'type'        => 'select',
						'default'     => 'standard',
						'options'     => array(
							'standard' => __( 'Standard', 'agend-apps-core' ),
							'header'   => __( 'Header panel: coloured title panel, facts strip and an apply box', 'agend-apps-core' ),
						),
						'description' => __( 'The header layout puts the job title in a coloured panel (set its colour under Style, Colours), with location, salary, job type and closing date in a strip beneath it.', 'agend-apps-core' ),
					),
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
					array(
						'name'    => 'visibility_note',
						'type'    => 'note',
						'content' => __( 'Who can see the board and each job\'s details is a site setting: Settings, Agend Widgets, Jobs visibility. The text below is what a visitor who needs to sign in sees.', 'agend-apps-core' ),
					),
					array(
						'name'        => 'signin_text',
						'label'       => __( 'Sign-in message', 'agend-apps-core' ),
						'type'        => 'textarea',
						'default'     => __( 'Sign in as a member to see the full job details.', 'agend-apps-core' ),
						'label_block' => true,
					),
					array(
						'name'    => 'signin_button',
						'label'   => __( 'Sign-in button text', 'agend-apps-core' ),
						'type'    => 'text',
						'default' => __( 'Sign in', 'agend-apps-core' ),
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
					array(
						'name'        => 'job_header_colour',
						'label'       => __( 'Job page header colour', 'agend-apps-core' ),
						'type'        => 'colour',
						'default'     => '',
						'description' => __( 'The panel behind the job title in the header job page layout. Blank uses the heading colour.', 'agend-apps-core' ),
						'condition'   => array( 'detail_layout' => 'header' ),
					),
				),
			),
		),
	);
}

/**
 * Category options for the jobs catalogue's "Only this category" picker,
 * keyed by category slug (what the list endpoint filters on), in the board's
 * display order. Empty outside an editor
 * {@see agend_apps_records_is_editor_request()}.
 *
 * @return array Options keyed by category slug, with an "all" entry first.
 */
function agend_apps_records_jobs_catalogue_category_options(): array {
	return array( '' => __( 'All categories', 'agend-apps-core' ) ) + agend_apps_records_jobs_catalogue_term_options( 'categories' );
}

/**
 * Tag options for the jobs catalogue's tag filters, keyed by tag slug.
 * Empty outside an editor.
 *
 * @return array Options keyed by tag slug.
 */
function agend_apps_records_jobs_catalogue_tag_options(): array {
	return agend_apps_records_jobs_catalogue_term_options( 'tags' );
}

/**
 * A board's active categories or tags as editor options, slug to name.
 *
 * @param string $kind `categories` or `tags`.
 * @return array<string, string>
 */
function agend_apps_records_jobs_catalogue_term_options( string $kind ): array {
	if ( ! function_exists( 'agend_apps_jobs_get_terms' ) || ! agend_apps_records_is_editor_request() || agend_apps_records_surface_gated( 'jobs-catalogue' ) ) {
		return array();
	}

	$response = agend_apps_jobs_get_terms( $kind );

	if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return array();
	}

	$options = array();
	foreach ( $response['data'] as $term ) {
		if ( is_array( $term ) && ! empty( $term['slug'] ) ) {
			$options[ (string) $term['slug'] ] = (string) ( $term['name'] ?? $term['slug'] );
		}
	}

	return $options;
}
