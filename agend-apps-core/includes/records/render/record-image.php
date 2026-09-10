<?php
/**
 * Server render of the record image surface (formerly the Elementor "Agend
 * Image" widget): the current record's image, as an image element or as a
 * background.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema( 'record-image' )`) and echoes the
 * returned HTML.
 *
 * Record images are remote gateway URLs, not media-library attachments, so
 * WordPress image sizes do not apply; sizing is done with aspect ratio and
 * object-fit instead. Background mode has three placements because free
 * Elementor's container background control cannot take a per-record URL:
 * `fill` stretches behind the sibling widgets of the container it sits in,
 * `parent` paints the URL onto the parent container itself (via
 * assets/js/record-fields.js), and `block` is an ordinary sized box.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The image URL for the current record: the record's own field value, or the
 * configured fallback, or ''.
 *
 * `fallback_image` is a `media` schema field: Elementor hands this an
 * `array( 'url' => ..., 'id' => ... )`, and a Gutenberg block stores the same
 * setting shape, but either could still arrive as a plain URL string (a site
 * mid-upgrade, or a hand-built settings array), so both shapes are read
 * defensively through agend_apps_records_normalise_url_setting() (format.php)
 * rather than assuming which one was given.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-image' )).
 * @param array $ctx      Resolved record context (see agend_apps_records_resolve_record_context()).
 * @return string
 */
function agend_apps_records_record_image_url( array $settings, array $ctx ): string {
	$key = (string) ( $settings['field'] ?? 'common:image' );
	$url = agend_apps_records_field_value( $key, $ctx['type'], $ctx['record'], $ctx['extra'] );
	$url = is_string( $url ) ? $url : '';

	if ( '' === $url ) {
		$url = agend_apps_records_normalise_url_setting( $settings['fallback_image'] ?? '' );
	}

	return $url;
}

/**
 * The CSS `background-image` value, including any overlay layers.
 *
 * `overlay_colour` is one of the four `adapter` fields (see the deviation note
 * in schema/record-image.php), but unlike the other three it has never driven
 * CSS through an Elementor selector: this has always read it directly and
 * baked it into the value as a gradient layer, so it renders identically with
 * or without Elementor's own stylesheet. That is why
 * agend_apps_records_record_image_inline_styles() below leaves it alone --
 * adding it there too would double the overlay rather than add a missing one.
 *
 * @param string $url      Image URL.
 * @param array  $settings Surface settings.
 * @return string
 */
function agend_apps_records_record_image_background_value( string $url, array $settings ): string {
	$layers = array();
	if ( 'yes' === ( $settings['overlay_gradient'] ?? '' ) ) {
		$layers[] = 'linear-gradient(180deg, rgba(30,42,74,0.35), rgba(30,42,74,0.85))';
	}
	$overlay = trim( (string) ( $settings['overlay_colour'] ?? '' ) );
	if ( '' !== $overlay ) {
		$layers[] = 'linear-gradient(' . $overlay . ', ' . $overlay . ')';
	}
	$layers[] = "url('" . esc_url( $url ) . "')";
	return implode( ', ', $layers );
}

/**
 * Normalises the `placement` setting to one of the three background
 * placements, defaulting an unset or invalid value to `fill`.
 *
 * Shared by the renderer (which uses it to choose the placement class and,
 * with `inline_style` on, whether `min_height` applies) and by the Elementor
 * widget (which uses it to decide whether to add its own wrapper element's
 * `agend-record-image-host--fill` class -- an Elementor-only concept the core
 * renderer has no access to, since it returns only the surface's own inner
 * markup, never the widget's wrapper). Stating the fallback here once is what
 * keeps the two callers from silently disagreeing about what an invalid
 * placement value means.
 *
 * @param array $settings Surface settings.
 * @return string 'fill', 'parent', or 'block'.
 */
function agend_apps_records_record_image_placement( array $settings ): string {
	$placement = (string) ( $settings['placement'] ?? 'fill' );

	return in_array( $placement, array( 'fill', 'parent', 'block' ), true ) ? $placement : 'fill';
}

/**
 * Inline-style equivalents of the three CSS-generating (`adapter`) settings
 * that have never produced markup of their own: `aspect_ratio`, `object_fit`
 * and `min_height` (see the deviation note in schema/record-image.php for why
 * they are `adapter` fields rather than the shared `select`/`number` types).
 * `overlay_colour`, the fourth adapter field, is deliberately not covered here
 * -- see agend_apps_records_record_image_background_value().
 *
 * Elementor applies the first three through its own generated stylesheet (the
 * `selectors` key on each control in
 * Agend_Elementor_Record_Image::register_adapter_control()), so today's
 * markup carries no trace of them and none of that changes here: a caller
 * that leaves `$opts['inline_style']` off -- the Elementor widget always does
 * -- gets exactly today's output, with no `style` attribute added for these.
 * A Gutenberg block has no such stylesheet to write selectors into, so it
 * needs the same three values applied as an inline `style` attribute instead;
 * this is the one place that translates a setting into its CSS declaration,
 * so a block renderer does not have to restate what each one means.
 *
 * @param array  $settings  Surface settings.
 * @param string $mode      'img' or 'background', already resolved.
 * @param string $placement Background placement, already resolved (see
 *                          agend_apps_records_record_image_placement()); ignored in 'img' mode.
 * @return array{img: string, block: string} CSS declarations (no `style="..."`
 *              wrapper) for the image element ('img' mode) and for the sized
 *              background block ('background' mode, 'block' placement). The
 *              value for the mode/placement combination not in play is always ''.
 */
function agend_apps_records_record_image_inline_styles( array $settings, string $mode, string $placement ): array {
	$img_style   = '';
	$block_style = '';

	if ( 'img' === $mode ) {
		$aspect_ratio = trim( (string) ( $settings['aspect_ratio'] ?? '16 / 9' ) );
		if ( '' !== $aspect_ratio ) {
			$img_style .= 'aspect-ratio:' . $aspect_ratio . ';';
		}
		$object_fit = trim( (string) ( $settings['object_fit'] ?? 'cover' ) );
		if ( '' !== $object_fit ) {
			$img_style .= 'object-fit:' . $object_fit . ';';
		}
	}

	if ( 'background' === $mode && 'block' === $placement ) {
		$min_height = is_array( $settings['min_height'] ?? null ) ? $settings['min_height'] : array();
		$size       = $min_height['size'] ?? 240;
		$unit       = (string) ( $min_height['unit'] ?? 'px' );
		if ( is_numeric( $size ) ) {
			$block_style .= 'min-height:' . $size . $unit . ';';
		}
	}

	return array(
		'img'   => $img_style,
		'block' => $block_style,
	);
}

/**
 * Resolves the whole "does this image render, and if not why" decision in one
 * pass: the record context and, when there is one, the image URL to use.
 *
 * The one condition that makes the image render nothing with something to
 * tell an author -- a record (or preview record) with no image and no
 * fallback configured -- is stated exactly once here, on
 * agend_apps_records_filter_resolve()'s shape: both
 * agend_apps_records_record_image_render_reason() and
 * agend_apps_records_render_record_image() read this single source of truth
 * instead of restating it. A record context that resolves to no type at all
 * (live, outside any template, and not a preview) also renders nothing, but
 * -- as with agend_apps_records_filter_render_reason() -- that is not a
 * reason either: `url` is '' either way, and it is the renderer's own
 * emptiness check that tells the two apart, since there is nothing more
 * specific to tell an author in that case.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-image' )).
 * @param array $opts     'preview' / 'preview_type', passed straight to
 *                        agend_apps_records_resolve_record_context().
 * @return array{reason: string, ctx: array, url: string} `reason` is '' or
 *              'no_image'; `url` is '' whenever there is nothing to render.
 */
function agend_apps_records_record_image_resolve( array $settings, array $opts = array() ): array {
	$ctx = agend_apps_records_resolve_record_context( $opts );

	if ( '' === $ctx['type'] ) {
		return array( 'reason' => '', 'ctx' => $ctx, 'url' => '' );
	}

	$url = agend_apps_records_record_image_url( $settings, $ctx );
	if ( '' === $url ) {
		return array( 'reason' => 'no_image', 'ctx' => $ctx, 'url' => '' );
	}

	return array( 'reason' => '', 'ctx' => $ctx, 'url' => $url );
}

/**
 * Why the record image with these settings renders nothing, or '' when it
 * renders. See agend_apps_records_record_image_resolve().
 *
 * @param array $settings Surface settings.
 * @param array $opts     See agend_apps_records_record_image_resolve().
 * @return string '' or 'no_image'.
 */
function agend_apps_records_record_image_render_reason( array $settings, array $opts = array() ): string {
	return agend_apps_records_record_image_resolve( $settings, $opts )['reason'];
}

/**
 * Renders the record image as a CSS background element.
 *
 * @param array  $settings     Surface settings.
 * @param string $url          Resolved image URL.
 * @param string $title        The record's title, for the `aria-label`.
 * @param bool   $inline_style See agend_apps_records_render_record_image().
 * @return string
 */
function agend_apps_records_record_image_render_background( array $settings, string $url, string $title, bool $inline_style ): string {
	$placement = agend_apps_records_record_image_placement( $settings );

	$style = sprintf(
		'background-image:%s;background-size:%s;background-position:%s;',
		agend_apps_records_record_image_background_value( $url, $settings ),
		(string) ( $settings['background_size'] ?? 'cover' ),
		(string) ( $settings['background_position'] ?? 'center center' )
	);

	if ( $inline_style ) {
		$style .= agend_apps_records_record_image_inline_styles( $settings, 'background', $placement )['block'];
	}

	// The fill placement positions the inner box against the parent
	// container; the wrapper class that requires is an Elementor-only concern
	// applied by the widget itself, since this markup carries only the
	// surface's own inner element.
	$attrs  = 'class="agend-record-image agend-record-image--bg agend-record-image--' . esc_attr( $placement ) . '"';
	$attrs .= ' style="' . esc_attr( $style ) . '"';
	$attrs .= ' role="img" aria-label="' . esc_attr( $title ) . '"';
	$attrs .= ' data-agend-bg-url="' . esc_url( $url ) . '"';
	$attrs .= ' data-agend-bg-style="' . esc_attr( $style ) . '"';
	if ( 'parent' === $placement ) {
		$attrs .= ' data-agend-bg-target="parent"';
	}

	return '<div ' . $attrs . '></div>';
}

/**
 * Renders the record image as an `<img>` element, optionally linked to the
 * record's detail page.
 *
 * @param array  $settings     Surface settings.
 * @param array  $ctx          Resolved record context.
 * @param string $url          Resolved image URL.
 * @param string $title        The record's title, for the `alt` text.
 * @param bool   $inline_style See agend_apps_records_render_record_image().
 * @return string
 */
function agend_apps_records_record_image_render_img( array $settings, array $ctx, string $url, string $title, bool $inline_style ): string {
	$attrs = 'class="agend-record-image agend-record-image--img"';

	if ( $inline_style ) {
		$style = agend_apps_records_record_image_inline_styles( $settings, 'img', '' )['img'];
		if ( '' !== $style ) {
			$attrs .= ' style="' . esc_attr( $style ) . '"';
		}
	}

	$attrs .= ' src="' . esc_url( $url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy"';

	$img = '<img ' . $attrs . ' />';

	$link = ( 'yes' === ( $settings['link_to_detail'] ?? '' ) && empty( $ctx['extra']['in_card_link'] ) )
		? (string) ( agend_apps_records_field_value( 'common:detail_url', $ctx['type'], $ctx['record'], $ctx['extra'] ) ?? '' )
		: '';

	if ( '' !== $link && '#' !== $link ) {
		$img = '<a class="agend-record-image__link" href="' . esc_url( $link ) . '">' . $img . '</a>';
	}

	return $img;
}

/**
 * Renders the record image surface: the current record's image, as an `<img>`
 * element or as a CSS background.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-image' )).
 * @param array $opts     'preview' (bool) / 'preview_type' (string): passed
 *                        straight to agend_apps_records_resolve_record_context().
 *                        'inline_style' (bool, default false): apply
 *                        `aspect_ratio`, `object_fit` and `min_height` as an
 *                        inline `style` attribute instead of leaving them to
 *                        Elementor's own generated stylesheet. Elementor
 *                        already renders those three through CSS selectors it
 *                        writes itself, so this stays off for the Elementor
 *                        widget, which keeps its markup byte-for-byte
 *                        unchanged; a Gutenberg block, which has no such
 *                        stylesheet, turns it on so the same settings still
 *                        take visible effect. See
 *                        agend_apps_records_record_image_inline_styles() for
 *                        the CSS each setting maps to, and for why
 *                        `overlay_colour` needs no such flag.
 * @return string The rendered markup, or '' when there is no image to show
 *                (see agend_apps_records_record_image_render_reason() for why,
 *                when a caller needs to know).
 */
function agend_apps_records_render_record_image( array $settings, array $opts = array() ): string {
	$resolved = agend_apps_records_record_image_resolve( $settings, $opts );
	if ( '' === $resolved['url'] ) {
		return '';
	}

	$ctx          = $resolved['ctx'];
	$url          = $resolved['url'];
	$title        = (string) ( agend_apps_records_field_value( 'common:title', $ctx['type'], $ctx['record'], $ctx['extra'] ) ?? '' );
	$inline_style = ! empty( $opts['inline_style'] );

	if ( 'background' === (string) ( $settings['mode'] ?? 'img' ) ) {
		return agend_apps_records_record_image_render_background( $settings, $url, $title, $inline_style );
	}

	return agend_apps_records_record_image_render_img( $settings, $ctx, $url, $title, $inline_style );
}
