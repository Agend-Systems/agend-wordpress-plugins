<?php
/**
 * Provisions the Shopping Centres Online directory prototype on pca.test:
 * a card template, a filter template and a page hosting the Directory
 * Catalogue widget. The page runs on the Elementor Canvas template, so the
 * theme header and footer are replaced by the SCO header and footer from the
 * design mock, built into the page itself. Idempotent: re-running updates the
 * same posts and reuses the sideloaded logos, found by the
 * `_sco_directory_role` marker meta.
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

function sco_find( string $role, array $types = array( 'elementor_library', 'page' ), string $status = 'any' ): int {
	$q = new WP_Query( array( 'post_type' => $types, 'post_status' => $status, 'meta_key' => '_sco_directory_role', 'meta_value' => $role, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ) );
	return $q->posts ? (int) $q->posts[0] : 0;
}

function sco_html( string $markup ): array {
	return sco_widget( 'html', array( 'html' => $markup, '_margin' => sco_box( 0 ) ) );
}

/**
 * One built-in fragment block. The colour settings are the widget's own
 * Colours controls, not a CSS override: a block renders the plugin's fragment
 * markup, which reads the catalogue colour variables, and left alone it emits
 * the plugin's default palette rather than PCA's.
 */
function sco_block( string $block, array $colours = array() ): array {
	return sco_widget( 'agend-record-block', array_merge( array( 'record_type' => 'auto', 'block' => $block, '_margin' => sco_box( 0 ) ), $colours ) );
}

/**
 * The SCO header as a full-width section. Built by both documents that need
 * the chrome (the catalogue page and the listing detail template) so the two
 * stay identical; the styling travels with the section as its own custom CSS,
 * which is why it works in a template as well as on a page.
 */
function sco_chrome_header( string $markup, string $css ): array {
	return sco_section(
		array( sco_column( 100, array( sco_html( $markup ) ) ) ),
		array( 'layout' => 'full_width', 'custom_css' => $css, 'css_classes' => 'sco-header-band' )
	);
}

/**
 * The SCO footer as a full-width section. See sco_chrome_header().
 */
function sco_chrome_footer( string $markup, string $css ): array {
	return sco_section(
		array( sco_column( 100, array( sco_html( $markup ) ) ) ),
		array( 'layout' => 'full_width', 'custom_css' => $css, 'css_classes' => 'sco-footer-band' )
	);
}

/**
 * Sideloads one of the mock's logos into the media library and returns its id.
 * Keyed by the marker meta, so re-running the script reuses the attachment
 * instead of piling up duplicates.
 */
function sco_media( string $role, string $filename, string $title ): int {
	global $sco_author;

	$id = sco_find( $role, array( 'attachment' ), 'inherit' );
	if ( $id && get_post( $id ) ) {
		return $id;
	}

	$source = __DIR__ . '/assets/' . $filename;
	if ( ! file_exists( $source ) ) {
		WP_CLI::error( sprintf( 'Missing logo asset: %s', $source ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = wp_tempnam( $filename );
	copy( $source, $tmp );

	$id = media_handle_sideload(
		array( 'name' => $filename, 'tmp_name' => $tmp ),
		0,
		$title,
		array( 'post_author' => $sco_author, 'post_content' => '' )
	);
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		WP_CLI::error( sprintf( 'Could not sideload %s: %s', $filename, $id->get_error_message() ) );
	}

	update_post_meta( $id, '_sco_directory_role', $role );
	update_post_meta( $id, '_wp_attachment_image_alt', $title );
	return (int) $id;
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
	delete_post_meta( $id, '_elementor_element_cache' );
	delete_post_meta( $id, '_elementor_page_assets' );
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
// 3. Header and footer from the mock
// ---------------------------------------------------------------------------
//
// The page runs on Elementor Canvas, which strips the theme header and footer,
// so both are rebuilt here as HTML widgets. Everything the mock draws with
// static copy is static; the member chip hydrates client-side from
// `window.agendApps.member` when an Agend member session exists, and otherwise
// from the WordPress REST `users/me` record, so one cached markup serves every
// member.

$sco_logo_id = sco_media( 'logo-sco', 'sco-logo-white.png', 'Shopping Centres Online' );
$pca_logo_id = sco_media( 'logo-pca', 'pca-logo-navy.png', 'Property Council of Australia' );

$header_html = <<<'HTML'
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow:wght@300;400;600;700;900&display=swap">
<header class="sco-header">
	<div class="sco-header__inner">
		<a class="sco-header__brand" href="%HOME%">
			<img src="%SCO_LOGO%" width="732" height="136" alt="Shopping Centres Online">
		</a>
		<div class="sco-header__user" data-sco-user hidden>
			<button type="button" class="sco-header__chip" data-sco-toggle aria-expanded="false" aria-haspopup="true">
				<span class="sco-header__avatar" data-sco-initials aria-hidden="true"></span>
				<span class="sco-header__name" data-sco-name></span>
				<span class="sco-header__caret" aria-hidden="true">&#9662;</span>
			</button>
			<div class="sco-header__menu" data-sco-menu hidden>
				<div class="sco-header__menu-head">
					<div class="sco-header__email" data-sco-email hidden></div>
					<div class="sco-header__expiry" data-sco-expiry hidden>
						<span class="sco-header__expiry-label">Expires</span>
						<span class="sco-header__expiry-value" data-sco-expiry-value></span>
					</div>
				</div>
				<div class="sco-header__divider"></div>
				<a class="sco-header__logout" href="%LOGOUT%">Log out</a>
			</div>
		</div>
		<a class="sco-header__login" data-sco-login href="%LOGIN%" hidden>Log in</a>
	</div>
</header>
<script>
( function () {
	var header = document.querySelector( '.sco-header' );
	if ( ! header ) {
		return;
	}

	var user   = header.querySelector( '[data-sco-user]' );
	var login  = header.querySelector( '[data-sco-login]' );
	var toggle = header.querySelector( '[data-sco-toggle]' );
	var menu   = header.querySelector( '[data-sco-menu]' );

	function initials( name ) {
		var parts = String( name || '' ).trim().split( /\s+/ ).filter( Boolean );
		if ( ! parts.length ) {
			return '•';
		}
		var last = parts.length > 1 ? parts[ parts.length - 1 ].charAt( 0 ) : '';
		return ( parts[ 0 ].charAt( 0 ) + last ).toUpperCase();
	}

	function set( attr, value ) {
		var el = header.querySelector( '[data-sco-' + attr + ']' );
		if ( ! el ) {
			return;
		}
		el.textContent = value || '';
		el.hidden = ! value;
	}

	function signedIn( member ) {
		header.querySelector( '[data-sco-name]' ).textContent = member.name || 'My account';
		header.querySelector( '[data-sco-initials]' ).textContent = initials( member.name );
		set( 'email', member.email );
		var expiry = header.querySelector( '[data-sco-expiry]' );
		if ( member.expires ) {
			header.querySelector( '[data-sco-expiry-value]' ).textContent = member.expires;
			expiry.hidden = false;
		}
		user.hidden = false;
		login.hidden = true;
	}

	function signedOut() {
		user.hidden = true;
		login.hidden = false;
	}

	var config = window.agendApps || {};
	if ( config.member && config.member.name ) {
		signedIn( config.member );
	} else if ( document.body.classList.contains( 'logged-in' ) ) {
		// No Agend member session, but WordPress knows who this is.
		signedIn( {} );
		fetch( '/wp-json/wp/v2/users/me?context=edit', {
			credentials: 'same-origin',
			headers: config.nonce ? { 'X-WP-Nonce': config.nonce } : {}
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : Promise.reject( response.status );
			} )
			.then( function ( me ) {
				signedIn( { name: me.name, email: me.email } );
			} )
			.catch( function () {} );
	} else {
		signedOut();
	}

	function close() {
		menu.hidden = true;
		toggle.setAttribute( 'aria-expanded', 'false' );
	}

	toggle.addEventListener( 'click', function ( event ) {
		event.stopPropagation();
		var opening = menu.hidden;
		menu.hidden = ! opening;
		toggle.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( ! user.contains( event.target ) ) {
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key ) {
			close();
		}
	} );
}() );
</script>
HTML;

$header_html = str_replace(
	array( '%HOME%', '%SCO_LOGO%', '%LOGIN%', '%LOGOUT%' ),
	array(
		esc_url( home_url( '/' ) ),
		esc_url( wp_get_attachment_url( $sco_logo_id ) ),
		esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url() ),
		esc_url( home_url( '/my-account/customer-logout/' ) ),
	),
	$header_html
);

$footer_html = <<<'HTML'
<footer class="sco-footer">
	<div class="sco-footer__grid">
		<div>
			<div class="sco-footer__heading">Property Council of Australia</div>
			<div class="sco-footer__lines">
				<span>Level 7,&nbsp;50 Carrington Street</span>
				<span>Sydney NSW 2000</span>
			</div>
		</div>
		<div class="sco-footer__mark">
			<img src="%PCA_LOGO%" width="243" height="213" alt="Property Council of Australia">
		</div>
		<div class="sco-footer__contact">
			<div class="sco-footer__heading">Get in touch</div>
			<div class="sco-footer__lines">
				<span>+61 (0)2 9033 1900</span>
				<span>ABN 13 008 474 422</span>
			</div>
		</div>
	</div>
	<div class="sco-footer__rule">
		<div class="sco-footer__bar">
			<div>
				<div><strong>Shopping Centres Online</strong>, the national retail property directory of the Property Council of Australia.</div>
				<div class="sco-footer__note">Data updated annually. All subscription prices ex GST.</div>
			</div>
			<div class="sco-footer__links">
				<a href="#">Subscriptions</a>
				<a href="#">Data methodology</a>
				<a href="#">Terms of use</a>
				<a href="#">Privacy</a>
			</div>
		</div>
	</div>
</footer>
HTML;

$footer_html = str_replace( '%PCA_LOGO%', esc_url( wp_get_attachment_url( $pca_logo_id ) ), $footer_html );

// The chrome's styling rides along with its sections as per-element custom CSS
// rather than living in the page's settings, so `selector` resolves to the
// section itself in whichever document renders it. That is what lets the
// catalogue page and the listing detail template share one definition.

$header_css = <<<'CSS'
selector { position:sticky; top:0; z-index:20; background:#001E60; }
selector::before, selector::after { display:none !important; content:none !important; }
selector .elementor-widget-wrap { padding:0 !important; }
selector .elementor-widget { margin-bottom:0; width:100%; }
selector [hidden] { display:none !important; }
selector .sco-header { display:flex; align-items:center; justify-content:center; padding:0 32px; color:#fff; flex-wrap:wrap; line-height:normal; }
selector .sco-header__inner { display:flex; align-items:center; justify-content:space-between; gap:16px; width:100%; max-width:1496px; padding:14px 32px; flex-wrap:wrap; box-sizing:border-box; }
selector .sco-header__brand { display:flex; align-items:center; gap:14px; flex-shrink:0; text-decoration:none; }
selector .sco-header__brand img { height:34px; width:auto; display:block; flex-shrink:0; }
selector .sco-header__user { position:relative; flex-shrink:0; }
selector .sco-header__chip { display:flex; align-items:center; gap:8px; background:transparent; border:none; cursor:pointer; padding:4px; flex-shrink:0; white-space:nowrap; color:#fff; font-family:inherit; }
selector .sco-header__avatar { width:28px; height:28px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; color:#24225C; flex-shrink:0; }
selector .sco-header__name { font-size:14px; white-space:nowrap; }
selector .sco-header__caret { color:oklch(75% 0.02 250); font-size:12px; flex-shrink:0; }
selector .sco-header__menu { position:absolute; top:44px; right:0; background:#fff; border:1px solid oklch(88% 0.01 250); border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,.15); padding:6px; z-index:30; min-width:250px; text-align:left; }
selector .sco-header__menu-head { padding:10px 12px 12px; }
selector .sco-header__email { font-size:14px; color:oklch(55% 0.01 250); overflow-wrap:anywhere; }
selector .sco-header__expiry { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:10px; padding-top:9px; border-top:1px solid oklch(94% 0.005 250); }
selector .sco-header__expiry-label { font-size:12px; font-weight:600; letter-spacing:.05em; text-transform:uppercase; color:oklch(62% 0.01 250); }
selector .sco-header__expiry-value { font-size:12px; font-weight:600; color:oklch(30% 0.01 250); }
selector .sco-header__divider { height:1px; background:oklch(92% 0.005 250); margin:2px 0; }
selector .sco-header__logout { display:block; padding:9px 12px; font-size:13px; font-weight:600; color:oklch(45% 0.15 25); border-radius:6px; text-decoration:none; }
selector .sco-header__logout:hover { background:oklch(96% 0.02 25); color:oklch(45% 0.15 25); }
selector .sco-header__login { font-size:14px; font-weight:600; color:#fff; text-decoration:none; padding:8px 18px; border:1px solid rgba(255,255,255,.45); border-radius:999px; white-space:nowrap; }
selector .sco-header__login:hover { background:rgba(255,255,255,.12); color:#fff; }
@media (max-width: 600px) {
	selector .sco-header { padding-left:16px; padding-right:16px; }
	selector .sco-header__inner { padding-left:16px; padding-right:16px; }
}
@media (max-width: 420px) {
	selector .sco-header__name { display:none; }
}
/* Unscoped on purpose: the theme's floating back-to-top button is not in the
   mock and it sits on top of the footer link row. This stylesheet only loads
   on a document that renders the SCO chrome, so the reach is right. */
.back-to-top { display:none !important; }
CSS;

$footer_css = <<<'CSS'
selector { background:#001E60; border-top:1px solid oklch(92% 0.008 240); }
selector::before, selector::after { display:none !important; content:none !important; }
selector .elementor-widget-wrap { padding:0 !important; }
selector .elementor-widget { margin-bottom:0; width:100%; }
selector .sco-footer { padding:32px; color:#fff; line-height:normal; }
selector .sco-footer__grid { max-width:1496px; margin:0 auto; display:grid; grid-template-columns:1fr auto 1fr; gap:32px; align-items:center; }
selector .sco-footer__heading { font-size:17px; font-weight:700; color:#fff; margin:0 0 14px; }
selector .sco-footer__lines { display:flex; flex-direction:column; gap:8px; font-size:13.5px; color:oklch(85% 0.01 250); }
selector .sco-footer__mark { display:flex; justify-content:center; }
selector .sco-footer__mark img { height:118px; width:135px; display:block; flex-grow:0; }
selector .sco-footer__contact { text-align:right; }
selector .sco-footer__contact .sco-footer__lines { align-items:flex-end; }
selector .sco-footer__rule { border-top:1px solid oklch(40% 0.03 250); margin-top:32px; }
selector .sco-footer__bar { max-width:1496px; margin:32px auto 0; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; font-size:12px; color:oklch(75% 0.02 250); box-sizing:border-box; }
selector .sco-footer__bar strong { color:#fff; font-weight:700; }
selector .sco-footer__note { margin-top:3px; }
selector .sco-footer__links { display:flex; gap:22px; flex-wrap:wrap; }
selector .sco-footer__links a { color:oklch(80% 0.01 250); text-decoration:none; }
selector .sco-footer__links a:hover { color:#fff; }
@media (max-width: 900px) {
	selector .sco-footer__grid { grid-template-columns:1fr; justify-items:center; text-align:center; }
	selector .sco-footer__contact { text-align:center; }
	selector .sco-footer__contact .sco-footer__lines { align-items:center; }
}
@media (max-width: 600px) {
	selector .sco-footer { padding-left:16px; padding-right:16px; }
}
CSS;

// ---------------------------------------------------------------------------
// 4. Listing detail template
// ---------------------------------------------------------------------------
//
// Configured as the Listing detail template, so the server-rendered detail page
// (/{page}/listing/{slug}/) renders this instead of the plugin's built-in
// layout. The point of having one at all is what it leaves out: the built-in
// detail opens with a five-star rating row and closes with a Reviews section
// plus a submission form, and PCA's shopping centres are not reviewed. Nothing
// here renders `listing:rating` or the `listing_reviews` block, so neither the
// stars nor the reviews appear.
//
// It carries the same header and footer sections as the catalogue page: a
// detail page is a virtual child of the catalogue page with no Elementor data
// of its own, so this template is the only place its chrome can come from.

// The same palette the Directory Catalogue widget carries on the catalogue
// page, so a listing detail matches the cards it came from. Passed to every
// fragment block below rather than forced with !important CSS, now that the
// block widget resolves its own Colours controls.
$block_colours = array(
	'inherit_colours'    => '',
	'heading_colour'     => '#101828',
	'body_colour'        => '#26304D',
	'accent_colour'      => '#001E60',
	'button_colour'      => '#009639',
	'button_text_colour' => '#FFFFFF',
);

$detail_css = <<<'CSS'
selector { font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; background:#DCEAED; color:#101828; }
selector::before, selector::after { display:none !important; content:none !important; }
selector > .elementor-container { max-width:1560px; }
selector .elementor-element-populated { padding:0; }
selector .elementor-widget { margin-bottom:0; width:100%; }
selector .elementor-inner-section { width:100%; }
/* Inner sections otherwise inherit the kit's boxed content width, which clamps
   the hero and the two-column layout narrower than the band around them. */
selector .elementor-inner-section > .elementor-container { max-width:100%; }

/* The blocks below carry the plugin's own detail markup, already styled as
   white cards on a light ground, and now coloured from each block widget's own
   Colours controls (see $block_colours), so nothing is restyled here. */

selector .sco-detail__back { margin-bottom:16px; }
selector .sco-detail__back .agend-record-link--text, selector .sco-detail__back .agend-record-link--text span { color:#24225C; font-weight:600; font-size:13px; text-decoration:none; }

selector .sco-detail__head { background:#fff; border:1px solid #E4E7EC; border-radius:10px; padding:20px 22px; margin-bottom:16px; }
selector .sco-detail__head > .elementor-container { align-items:center; gap:12px; flex-wrap:nowrap; }
/* Content-sized columns, so a listing with no centre type leaves an empty pill
   column of zero width instead of a 30% gap that wraps on a narrow screen. */
selector .sco-detail__head > .elementor-container > .elementor-column:first-child { flex:1 1 auto; width:auto; min-width:0; }
selector .sco-detail__head > .elementor-container > .elementor-column:last-child { flex:0 0 auto; width:auto; }
selector .sco-detail__head .agend-field--common-title { font-size:26px; font-weight:900; line-height:1.15; margin:0; color:#101828; }
selector .sco-detail__head .agend-field--listing-address { color:#667085; font-size:13.5px; line-height:1.4; margin:6px 0 0; }
selector .sco-detail__head .agend-pills { display:flex; justify-content:flex-end; }
selector .sco-detail__head .agend-pill { background:#DCEAED; color:#24225C; font-size:12px; font-weight:600; padding:3px 10px; border-radius:999px; white-space:nowrap; text-decoration:none; }

selector .sco-detail__layout > .elementor-container { align-items:flex-start; gap:16px; flex-wrap:nowrap; }
/* Explicit widths rather than Elementor's column-size presets: 34% is not one
   of them, so the sidebar fell back to auto and collapsed. */
selector .sco-detail__layout > .elementor-container > .sco-detail__main { flex:1 1 auto; width:auto; min-width:0; }
selector .sco-detail__layout > .elementor-container > .sco-detail__side { flex:0 0 320px; width:320px; }
selector .sco-detail__main .elementor-widget-wrap, selector .sco-detail__side .elementor-widget-wrap { display:flex; flex-direction:column; gap:16px; flex-wrap:nowrap; }
selector .sco-detail__side .elementor-widget-wrap { position:sticky; top:88px; }
selector .agend-dir-detail__section, selector .agend-dir-detail__panel { margin:0; }
selector .agend-dir-detail__section-title { font-size:16px; font-weight:700; margin:0 0 12px; color:#101828; }

@media (max-width: 900px) {
	selector .sco-detail__layout > .elementor-container { flex-wrap:wrap; }
	selector .sco-detail__layout > .elementor-container > .sco-detail__main,
	selector .sco-detail__layout > .elementor-container > .sco-detail__side { flex:1 1 100%; width:100%; }
	selector .sco-detail__side .elementor-widget-wrap { position:static; }
}
@media (max-width: 600px) {
	selector { padding-left:16px; padding-right:16px; }
	selector .sco-detail__head { padding:16px; }
	selector .sco-detail__head .agend-field--common-title { font-size:22px; }
}
CSS;

$detail_data = array(
	sco_chrome_header( $header_html, $header_css ),
	sco_section(
		array(
			sco_column(
				100,
				array(
					sco_widget( 'agend-record-link', array( 'record_type' => 'auto', 'action' => 'catalogue', 'text' => '← Back to directory', 'style_as' => 'link', '_css_classes' => 'sco-detail__back', '_margin' => sco_box( 0 ) ) ),
					sco_section(
						array(
							sco_column(
								70,
								array(
									sco_field( 'common:title', array( 'html_tag' => 'h1' ) ),
									sco_field( 'listing:address' ),
								)
							),
							sco_column(
								30,
								array( sco_widget( 'agend-record-pills', array( 'record_type' => 'auto', 'field' => 'listing:category', 'max_items' => 1, '_margin' => sco_box( 0 ) ) ) )
							),
						),
						array( 'css_classes' => 'sco-detail__head' ),
						true
					),
					sco_section(
						array(
							sco_column(
								66,
								array(
									sco_block( 'listing_about', $block_colours ),
									sco_block( 'listing_custom_fields', $block_colours ),
									sco_block( 'listing_locations', $block_colours ),
									sco_block( 'listing_gallery', $block_colours ),
									sco_block( 'listing_hours', $block_colours ),
									sco_block( 'listing_tags', $block_colours ),
								),
								array( 'css_classes' => 'sco-detail__main' )
							),
							sco_column(
								34,
								array(
									sco_block( 'listing_contact', $block_colours ),
									sco_block( 'listing_categories', $block_colours ),
								),
								array( 'css_classes' => 'sco-detail__side' )
							),
						),
						array( 'css_classes' => 'sco-detail__layout' ),
						true
					),
				)
			),
		),
		array(
			'layout'        => 'boxed',
			'content_width' => array( 'unit' => 'px', 'size' => 1560 ),
			'padding'       => array( 'unit' => 'px', 'top' => '24', 'right' => '32', 'bottom' => '40', 'left' => '32', 'isLinked' => false ),
			'custom_css'    => $detail_css,
			'css_classes'   => 'sco-detail-band',
		)
	),
	sco_chrome_footer( $footer_html, $footer_css ),
);

$detail_id = sco_save(
	'detail',
	array( 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => 'SCO Listing Detail', 'post_content' => '' ),
	$detail_data,
	'section'
);

update_option( 'agend_elementor_listing_detail_template', $detail_id );

// ---------------------------------------------------------------------------
// 5. Catalogue page
// ---------------------------------------------------------------------------

$page_css = <<<'CSS'
selector { font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; background:#fff; color:#101828; }
/* Directory */
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
/* !important because page-settings CSS is a weaker selector than the element
   rule Elementor generates for the section's own padding control. */
@media (max-width: 600px) { selector .sco-directory-band { padding-left:16px !important; padding-right:16px !important; } }
CSS;

$page_data = array(
	sco_chrome_header( $header_html, $header_css ),
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
	sco_chrome_footer( $footer_html, $footer_css ),
);

$page_id = sco_save(
	'page',
	array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Shopping Centres Directory', 'post_name' => 'shopping-centres-directory', 'post_content' => '' ),
	$page_data,
	'wp-page',
	array( 'custom_css' => $page_css, 'hide_title' => 'yes' )
);
// Elementor Canvas: no theme header, footer or hero, so the SCO header and
// footer sections above are the only chrome on the page.
update_post_meta( $page_id, '_wp_page_template', 'elementor_canvas' );

// Regenerate CSS and refresh the template picker cache.
\Elementor\Plugin::$instance->files_manager->clear_cache();
delete_transient( 'agend_elementor_template_options' );
if ( function_exists( 'agend_apps_directory_flush_cache' ) ) {
	agend_apps_directory_flush_cache();
}

WP_CLI::success( sprintf( 'card=%d filters=%d detail=%d page=%d url=%s', $card_id, $filter_id, $detail_id, $page_id, get_permalink( $page_id ) ) );
