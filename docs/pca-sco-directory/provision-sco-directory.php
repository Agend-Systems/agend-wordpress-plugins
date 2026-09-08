<?php
/**
 * Provisions the Shopping Centres Online directory prototype on pca.test:
 * a card template, a filter template and a page hosting the Directory
 * Catalogue widget. Idempotent: re-running updates the same three posts,
 * found by the `_sco_directory_role` marker meta.
 *
 * Run: wp eval-file provision-sco-directory.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sco_author = 13890; // anjana_iugo (administrator), so the posts are editable.

function sco_id(): string {
	// Deterministic, so re-running keeps element ids (and the generated CSS) stable.
	static $n = 0;
	return substr( md5( 'sco-directory-' . $n++ ), 0, 7 );
}

function sco_widget( string $type, array $settings ): array {
	return array( 'id' => sco_id(), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
}

function sco_column( int $size, array $elements, array $settings = array() ): array {
	return array(
		'id'       => sco_id(),
		'elType'   => 'column',
		'settings' => array_merge( array( '_column_size' => $size, '_inline_size' => null, 'padding' => sco_box( 0 ) ), $settings ),
		'elements' => $elements,
		'isInner'  => false,
	);
}

function sco_section( array $columns, array $settings = array(), bool $inner = false ): array {
	foreach ( $columns as &$col ) {
		$col['isInner'] = $inner;
	}
	return array(
		'id'       => sco_id(),
		'elType'   => 'section',
		'settings' => array_merge( array( 'gap' => 'no', 'padding' => sco_box( 0 ), 'margin' => sco_box( 0 ) ), $settings ),
		'elements' => $columns,
		'isInner'  => $inner,
	);
}

function sco_box( $v, $unit = 'px' ): array {
	return array( 'unit' => $unit, 'top' => (string) $v, 'right' => (string) $v, 'bottom' => (string) $v, 'left' => (string) $v, 'isLinked' => true );
}

function sco_field( string $field, array $extra = array() ): array {
	return sco_widget( 'agend-record-field', array_merge( array( 'record_type' => 'auto', 'field' => $field, 'html_tag' => 'div', '_margin' => sco_box( 0 ) ), $extra ) );
}

function sco_label( string $text ): array {
	return sco_widget( 'heading', array( 'title' => $text, 'header_size' => 'span', 'size' => 'default', '_css_classes' => 'sco-card__label', '_margin' => sco_box( 0 ) ) );
}

function sco_row( string $label, array $value_widget ): array {
	$value_widget['settings']['_css_classes'] = 'sco-card__value';
	return sco_section(
		array( sco_column( 40, array( sco_label( $label ) ) ), sco_column( 60, array( $value_widget ) ) ),
		array( 'css_classes' => 'sco-card__row' ),
		true
	);
}

function sco_find( string $role ): int {
	$q = new WP_Query( array( 'post_type' => array( 'elementor_library', 'page' ), 'post_status' => 'any', 'meta_key' => '_sco_directory_role', 'meta_value' => $role, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ) );
	return $q->posts ? (int) $q->posts[0] : 0;
}

function sco_save( string $role, array $post, array $data, string $template_type, array $page_settings = array() ): int {
	global $sco_author;
	$id = sco_find( $role );
	$post['post_author'] = $sco_author;
	if ( $id ) {
		$post['ID'] = $id;
		wp_update_post( $post );
	} else {
		$id = (int) wp_insert_post( $post );
	}
	update_post_meta( $id, '_sco_directory_role', $role );
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_template_type', $template_type );
	update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
	if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
		update_post_meta( $id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
	}
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	if ( $page_settings ) {
		update_post_meta( $id, '_elementor_page_settings', $page_settings );
	}
	delete_post_meta( $id, '_elementor_css' );
	if ( 'elementor_library' === $post['post_type'] ) {
		wp_set_object_terms( $id, $template_type, 'elementor_library_type' );
	}
	return $id;
}

// ---------------------------------------------------------------------------
// 1. Card template
// ---------------------------------------------------------------------------

$card_css = <<<'CSS'
selector { background:#fff; border:1px solid #E4E7EC; border-radius:10px; padding:16px; height:100%; box-sizing:border-box; transition:box-shadow .15s ease, border-color .15s ease, transform .15s ease; font-size:12px; line-height:1.35; color:#101828; }
.agend-card-link:hover selector { box-shadow:0 6px 16px rgba(23,27,96,.12); border-color:#B9C4D0; transform:translateY(-2px); }
selector > .elementor-container, selector .elementor-column, selector .elementor-widget-wrap { height:100%; }
selector .elementor-widget-wrap { padding:0 !important; display:flex; flex-direction:column; flex-wrap:nowrap; align-content:stretch; }
selector .elementor-column-gap-no > .elementor-column > .elementor-element-populated { padding:0; }
selector .elementor-widget { margin-bottom:0; width:100%; }
selector .elementor-inner-section { width:100%; }
selector .elementor-inner-section > .elementor-container { flex-wrap:nowrap; }
selector .elementor-inner-section .elementor-widget-wrap { height:auto; }
selector .sco-card__head > .elementor-container { align-items:flex-start; gap:8px; }
selector .sco-card__head .elementor-column { width:auto; flex:1 1 auto; }
selector .sco-card__head .elementor-column:last-child { flex:0 0 auto; }
selector .agend-field--common-title { font-size:14px; font-weight:700; line-height:1.2; margin:0; color:#101828; }
selector .agend-pills { display:flex; justify-content:flex-end; }
selector .agend-pill { background:#DCEAED; color:#24225C; font-size:12px; font-weight:600; padding:2px 8px; border-radius:999px; white-space:nowrap; text-decoration:none; }
selector .agend-field--listing-address { color:#667085; margin:6px 0; }
selector .sco-card__row { padding:6px 0; border-top:1px solid #F2F4F7; }
selector .sco-card__row > .elementor-container { justify-content:space-between; gap:12px; }
selector .sco-card__label .elementor-heading-title { font-size:12px; font-weight:400; color:#98A2B3; margin:0; line-height:1.35; }
selector .sco-card__value .agend-field { font-weight:600; text-align:right; }
selector .sco-card__foot { margin-top:auto; padding-top:9px; }
selector .sco-card__foot > .elementor-container { align-items:center; justify-content:space-between; gap:8px; }
selector .agend-field--listing-updated_at { color:#B0B7C3; white-space:nowrap; }
selector .agend-record-link--text, selector .agend-record-link--text span { color:#24225C; font-weight:600; font-size:12px; white-space:nowrap; text-decoration:none; display:block; text-align:right; }
CSS;

$card_data = array(
	sco_section(
		array(
			sco_column(
				100,
				array(
					sco_section(
						array(
							sco_column( 70, array( sco_field( 'common:title', array( 'html_tag' => 'h3' ) ) ) ),
							sco_column( 30, array( sco_widget( 'agend-record-pills', array( 'record_type' => 'auto', 'field' => 'listing:category', 'max_items' => 1, '_margin' => sco_box( 0 ) ) ) ) ),
						),
						array( 'css_classes' => 'sco-card__head' ),
						true
					),
					sco_field( 'listing:address' ),
					sco_row( 'Suburb', sco_field( 'listing:location', array( 'fallback_text' => '—' ) ) ),
					sco_row( 'Owner', sco_field( 'common:custom_field', array( 'custom_field_key' => 'owners', 'fallback_text' => '—' ) ) ),
					sco_row( 'Asset owner', sco_field( 'common:custom_field', array( 'custom_field_key' => 'asset_owners', 'fallback_text' => '—' ) ) ),
					sco_row( 'GLAR (m²)', sco_field( 'common:custom_field', array( 'custom_field_key' => 'total_centre_glar_sqm', 'fallback_text' => '—' ) ) ),
					sco_section(
						array(
							sco_column( 50, array( sco_field( 'listing:updated_at', array( 'date_format' => 'custom', 'date_format_custom' => 'M Y' ) ) ) ),
							sco_column( 50, array( sco_widget( 'agend-record-link', array( 'record_type' => 'auto', 'action' => 'detail', 'text' => 'View profile →', 'style_as' => 'link', '_margin' => sco_box( 0 ) ) ) ) ),
						),
						array( 'css_classes' => 'sco-card__foot' ),
						true
					),
				)
			),
		),
		array( 'layout' => 'full_width', 'custom_css' => $card_css, 'css_classes' => 'sco-card' )
	),
);

$card_id = sco_save(
	'card',
	array( 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'SCO Directory Card', 'post_content' => '' ),
	$card_data,
	'section'
);

// ---------------------------------------------------------------------------
// 2. Filter template
// ---------------------------------------------------------------------------

$filter_css = <<<'CSS'
selector { background:#fff; border:1px solid #E4E7EC; border-radius:10px; padding:18px; box-sizing:border-box; font-size:12.5px; color:#101828; }
selector .elementor-column-gap-no > .elementor-column > .elementor-element-populated { padding:0; }
selector .elementor-widget { margin-bottom:12px; }
selector .elementor-widget:last-child { margin-bottom:0; }
selector .sco-filter__title .elementor-heading-title { font-size:14px; font-weight:700; margin:0 0 2px; color:#101828; }
selector .agend-filter__label { display:block; font-size:12px; color:#6B7280; font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-bottom:5px; }
selector input[type="text"], selector input[type="search"], selector input[type="number"], selector select { width:100%; padding:8px 10px; border:1px solid #D0D5DD; border-radius:6px; font-size:12.5px; font-family:inherit; background:#fff; color:#101828; box-sizing:border-box; min-height:36px; }
selector select { appearance:none; -webkit-appearance:none; padding-right:30px; background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none'><path d='M6 9l6 6 6-6' stroke='%23667085' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/></svg>"); background-repeat:no-repeat; background-position:right 10px center; background-size:12px; }
selector .sco-filter__search input { border-radius:8px; }
selector .agend-filter__range { display:flex; gap:8px; }
selector .agend-filter__reset { width:100%; margin-top:6px; padding:10px 16px; border:1px solid #D0D5DD; border-radius:999px; background:#fff; color:#344054; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
selector .agend-filter__reset:hover { border-color:#24225C; color:#24225C; }
selector .sco-filter__reset { border-top:1px solid #EEF0F3; padding-top:10px; }
CSS;

function sco_filter( string $filter, array $extra = array() ): array {
	return sco_widget( 'agend-filter', array_merge( array( 'filter' => $filter, 'show_label' => 'yes', 'values_mode' => 'all', '_margin' => sco_box( 0 ) ), $extra ) );
}

$filter_data = array(
	sco_section(
		array(
			sco_column(
				100,
				array(
					sco_widget( 'heading', array( 'title' => 'Filter', 'header_size' => 'h3', '_css_classes' => 'sco-filter__title', '_margin' => sco_box( 0 ) ) ),
					sco_filter( 'listing:search', array( 'control' => 'search', 'show_label' => '', 'placeholder' => 'Search centre name…', '_css_classes' => 'sco-filter__search' ) ),
					sco_filter( 'listing:category', array( 'control' => 'select', 'label' => 'Centre type', 'any_label' => 'All types' ) ),
					sco_filter( 'listing:custom_field', array( 'custom_field_key' => 'owners', 'control' => 'select', 'label' => 'Owner', 'any_label' => 'All owners' ) ),
					sco_filter( 'listing:custom_field', array( 'custom_field_key' => 'asset_owners', 'control' => 'select', 'label' => 'Asset owner', 'any_label' => 'All asset owners' ) ),
					sco_filter( 'listing:custom_field', array( 'custom_field_key' => 'total_centre_glar_sqm', 'control' => 'range', 'label' => 'GLAR (m²)' ) ),
					sco_filter( 'listing:reset', array( 'control' => 'reset', 'show_label' => '', 'label' => 'Reset', '_css_classes' => 'sco-filter__reset' ) ),
				)
			),
		),
		array( 'layout' => 'full_width', 'custom_css' => $filter_css, 'css_classes' => 'sco-filter' )
	),
);

$filter_id = sco_save(
	'filters',
	array( 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'SCO Directory Filters', 'post_content' => '' ),
	$filter_data,
	'section'
);

// ---------------------------------------------------------------------------
// 3. Page
// ---------------------------------------------------------------------------

$page_css = <<<'CSS'
selector .sco-directory-band { background:#DCEAED; border-top:1px solid #E4E7EC; }
selector .sco-directory-band::before, selector .sco-directory-band::after { display:none !important; content:none !important; }
selector .sco-directory-band > .elementor-container { max-width:1560px; }
selector .agend-directory-catalogue { --agend-filter-column:250px; font-size:13px; }
selector .agend-dir-heading { display:flex; align-items:center; gap:12px; margin-bottom:16px; }
selector .agend-dir-heading__title { font-size:18px; font-weight:700; margin:0; color:#101828; }
selector .agend-dir-heading__subtitle { margin:0; color:#24225C; font-size:12px; font-weight:600; padding:4px 12px; border-radius:999px; background:#fff; }
selector .agend-filters-left { column-gap:20px; }
selector .agend-dir-filter-slot { position:sticky; top:88px; }
selector .agend-dir-grid--templated { gap:14px; }
selector .agend-dir-grid--templated > .agend-card-link { display:flex; flex-direction:column; height:100%; }
selector .agend-dir-grid--templated > .agend-card-link > .elementor { flex:1 1 auto; display:flex; flex-direction:column; width:100%; }
selector .agend-dir-grid--templated > .agend-card-link .sco-card { flex:1 1 auto; }
selector .agend-dir-pager-slot { display:flex; justify-content:center; gap:6px; padding:20px 0 0; flex-wrap:wrap; }
selector .agend-dir-page { min-width:38px; padding:9px 12px; border:1px solid #D0D5DD; border-radius:999px; background:#fff; color:#24225C; font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
selector .agend-dir-page:hover { border-color:#24225C; }
selector .agend-dir-page.is-active { background:#001E60; border-color:#001E60; color:#fff; }
selector .agend-dir-status { text-align:center; padding:40px 0; color:#667085; font-size:14px; }
@media (max-width: 900px) { selector .agend-dir-filter-slot { position:static; } }
CSS;

$page_data = array(
	sco_section(
		array(
			sco_column(
				100,
				array(
					sco_widget(
						'agend-directory-catalogue',
						array(
							'show_heading'       => 'yes',
							'heading_text'       => 'Directory',
							'subheading_text'    => 'Australian shopping centres',
							'layout_style'       => 'grid',
							'columns_desktop'    => '3',
							'columns_tablet'     => '2',
							'columns_mobile'     => '1',
							'card_radius'        => array( 'unit' => 'px', 'size' => 10 ),
							'card_template'      => (string) $card_id,
							'card_link_whole'    => 'yes',
							'filter_template'    => (string) $filter_id,
							'filter_position'    => 'left',
							'pagination_style'   => 'numbered',
							'per_page'           => 12,
							'inherit_colours'    => '',
							'heading_colour'     => '#101828',
							'body_colour'        => '#26304D',
							'accent_colour'      => '#001E60',
							'button_colour'      => '#009639',
							'button_text_colour' => '#FFFFFF',
							'inherit_fonts'      => 'yes',
						)
					),
				)
			),
		),
		array(
			'layout'       => 'boxed',
			'content_width' => array( 'unit' => 'px', 'size' => 1560 ),
			'gap'          => 'no',
			'padding'      => array( 'unit' => 'px', 'top' => '24', 'right' => '32', 'bottom' => '40', 'left' => '32', 'isLinked' => false ),
			'css_classes' => 'sco-directory-band',
		)
	),
);

$page_id = sco_save(
	'page',
	array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Shopping Centres Directory', 'post_name' => 'shopping-centres-directory', 'post_content' => '' ),
	$page_data,
	'wp-page',
	array( 'custom_css' => $page_css, 'hide_title' => 'yes' )
);
update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );

// Regenerate CSS and refresh the template picker cache.
\Elementor\Plugin::$instance->files_manager->clear_cache();
delete_transient( 'agend_elementor_template_options' );
if ( function_exists( 'agend_apps_directory_flush_cache' ) ) {
	agend_apps_directory_flush_cache();
}

WP_CLI::success( sprintf( 'card=%d filters=%d page=%d url=%s', $card_id, $filter_id, $page_id, get_permalink( $page_id ) ) );
