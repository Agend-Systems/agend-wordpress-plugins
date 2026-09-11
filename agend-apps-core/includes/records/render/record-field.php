<?php
/**
 * Server render of the record field surface: one value from the record in
 * scope (title, date, price, venue ...), for use inside card and detail
 * templates.
 *
 * Page-builder agnostic: the same markup whichever editor placed the
 * surface. An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * Which record is "current" is not this file's concern:
 * agend_apps_records_resolve_record_context() answers that, from either the
 * pushed {@see Agend_Apps_Records_Record_Context} frame a template renderer
 * left behind, or an editor preview record.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the whole "does this field render, and if not why" decision in one
 * pass: the record context in scope, which field key and kind the settings
 * name, and whether that key applies to the record type in scope.
 *
 * `renders` is false for two cases that carry no more specific reason than
 * that, the same treatment agend_apps_records_filter_render_reason() gives its
 * own silent cases:
 * - no record in scope at all (no pushed context, and no preview requested),
 *   so there is nothing to read a field from;
 * - a `field` setting naming no key this site's registry knows. The schema's
 *   picker only ever offers real keys, so this is reachable only via a stale
 *   or hand-edited setting, which is not something an author needs telling.
 *
 * A key the registry does know, but that does not apply to the record type in
 * scope (a Course field inside an Event template), is the one case with
 * something worth saying, so it alone carries a reason.
 *
 * agend_apps_records_record_field_render_reason() and
 * agend_apps_records_render_record_field() both read this single source of
 * truth instead of restating its conditions, so the two cannot drift apart.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-field' )).
 * @param array $opts     Passed straight through to
 *                        agend_apps_records_resolve_record_context(): 'preview'
 *                        and 'preview_type'.
 * @return array{renders: bool, reason: string, ctx: array, key: string, kind: string}
 *               `reason` is '', or 'field_not_applicable'. `renders` is false
 *               whenever `reason` is non-'', but also for the two silent cases
 *               above, so a caller checks `renders` to decide whether to
 *               render and `reason` only to decide whether there is something
 *               worth telling an author.
 */
function agend_apps_records_record_field_resolve( array $settings, array $opts = array() ): array {
	$ctx = agend_apps_records_resolve_record_context( $opts );

	if ( '' === $ctx['type'] ) {
		return array( 'renders' => false, 'reason' => '', 'ctx' => $ctx, 'key' => '', 'kind' => '' );
	}

	$key  = (string) ( $settings['field'] ?? 'common:title' );
	$kind = agend_apps_records_field_kind( $key );
	if ( '' === $kind ) {
		return array( 'renders' => false, 'reason' => '', 'ctx' => $ctx, 'key' => $key, 'kind' => '' );
	}

	if ( ! agend_apps_records_field_applies( $key, $ctx['type'] ) ) {
		return array( 'renders' => false, 'reason' => 'field_not_applicable', 'ctx' => $ctx, 'key' => $key, 'kind' => $kind );
	}

	return array( 'renders' => true, 'reason' => '', 'ctx' => $ctx, 'key' => $key, 'kind' => $kind );
}

/**
 * Why a record field with these settings renders nothing, or '' when it
 * renders.
 *
 * Moved out here, as a stable reason code rather than a message, so a caller
 * other than the Elementor widget (a block's own editor) can surface the same
 * diagnostic without duplicating the conditions that trigger it; the widget
 * still owns the translated copy and the decision to show it at all
 * (render_editor_notice() is an editor-only concern and stays there).
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-field' )).
 * @param array $opts     Passed straight through to
 *                        agend_apps_records_resolve_record_context().
 * @return string '' or 'field_not_applicable'.
 */
function agend_apps_records_record_field_render_reason( array $settings, array $opts = array() ): string {
	return agend_apps_records_record_field_resolve( $settings, $opts )['reason'];
}

/**
 * Formatting options for agend_apps_records_format_field() from the surface
 * settings.
 *
 * @param array $s Surface settings.
 * @return array
 */
function agend_apps_records_record_field_format_options( array $s ): array {
	$date_format = (string) ( $s['date_format'] ?? '' );
	if ( 'custom' === $date_format ) {
		$date_format = (string) ( $s['date_format_custom'] ?? '' );
	}
	return array(
		'date_format'      => $date_format,
		'price_free_label' => (string) ( $s['price_free_label'] ?? '' ),
		'list_separator'   => (string) ( $s['list_separator'] ?? ', ' ),
		'list_max'         => (int) ( $s['list_max'] ?? 0 ),
		'bool_true'        => (string) ( $s['bool_true'] ?? '' ),
		'bool_false'       => (string) ( $s['bool_false'] ?? '' ),
		'number_suffix'    => (string) ( $s['number_suffix'] ?? '' ),
		'truncate'         => (int) ( $s['truncate_chars'] ?? 0 ),
	);
}

/**
 * The custom field key this surface reads.
 *
 * The picker wins when it names a key; the free-text control is what an
 * author uses for a field this site's key cannot enumerate, or one that does
 * not exist yet. Settings saved before the picker existed have only the
 * free-text value, which is why the picker's empty option leaves that control
 * visible rather than hiding a key the render is still using.
 *
 * @param array $s Surface settings.
 * @return string
 */
function agend_apps_records_record_field_custom_field_key( array $s ): string {
	$choice = trim( (string) ( $s['custom_field_key_choice'] ?? '' ) );

	return '' !== $choice ? $choice : trim( (string) ( $s['custom_field_key'] ?? '' ) );
}

/**
 * The label element, or '' when the surface is not showing one.
 *
 * @param array  $s      Surface settings.
 * @param string $key    Field key.
 * @param array  $record The record.
 * @param array  $extra  Render context.
 * @return string Escaped HTML.
 */
function agend_apps_records_record_field_label_html( array $s, string $key, array $record, array $extra ): string {
	if ( 'yes' !== ( $s['show_label'] ?? '' ) ) {
		return '';
	}

	$text = trim( (string) ( $s['label_text'] ?? '' ) );
	if ( '' === $text ) {
		$text = agend_apps_records_field_label( $key, $record, $extra );
	}
	if ( '' === $text ) {
		return '';
	}

	return '<span class="agend-field__label">' . esc_html( $text )
		. esc_html( (string) ( $s['label_separator'] ?? '' ) ) . '</span>';
}

/**
 * Renders the record field surface: a single formatted value from the record
 * in scope, with the label, before/after text and detail link the settings
 * ask for.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-field' )).
 * @param array $opts     Passed straight through to
 *                        agend_apps_records_resolve_record_context(): 'preview'
 *                        (bool, default false) and 'preview_type' (string).
 * @return string The rendered markup, or '' when the field renders nothing
 *                (see agend_apps_records_record_field_render_reason() for why,
 *                when a caller needs to know).
 */
function agend_apps_records_render_record_field( array $settings, array $opts = array() ): string {
	$resolved = agend_apps_records_record_field_resolve( $settings, $opts );
	if ( ! $resolved['renders'] ) {
		return '';
	}
	// agend_apps_records_record_field_resolve() only ever returns renders =>
	// true alongside a known key and kind, so both are safe to use as-is.
	$ctx  = $resolved['ctx'];
	$key  = $resolved['key'];
	$kind = $resolved['kind'];

	$extra                     = $ctx['extra'];
	$extra['custom_field_key'] = agend_apps_records_record_field_custom_field_key( $settings );

	$html = agend_apps_records_render_field( $key, $ctx['type'], $ctx['record'], $extra, agend_apps_records_record_field_format_options( $settings ) );
	if ( '' === $html ) {
		$fallback = trim( (string) ( $settings['fallback_text'] ?? '' ) );
		if ( '' === $fallback ) {
			return '';
		}
		$html = esc_html( $fallback );
	}

	$before = (string) ( $settings['before_text'] ?? '' );
	$after  = (string) ( $settings['after_text'] ?? '' );
	if ( 'html' === $kind ) {
		$inner = $html;
	} else {
		$inner = esc_html( $before ) . $html . esc_html( $after );
	}

	// A card that is already one big link cannot contain another anchor.
	$link = ( 'yes' === ( $settings['link_to_detail'] ?? '' ) && empty( $extra['in_card_link'] ) )
		? (string) agend_apps_records_field_value( 'common:detail_url', $ctx['type'], $ctx['record'], $extra )
		: '';
	if ( '' !== $link && '#' !== $link ) {
		$inner = '<a class="agend-field__link" href="' . esc_url( $link ) . '">' . $inner . '</a>';
	}

	$tag = (string) ( $settings['html_tag'] ?? 'div' );
	if ( ! in_array( $tag, array( 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
		$tag = 'div';
	}

	$classes = array( 'agend-field', 'agend-field--' . $kind, 'agend-field--' . str_replace( ':', '-', $key ) );
	if ( $ctx['is_preview'] ) {
		$classes[] = 'agend-field--preview';
	}

	$label = agend_apps_records_record_field_label_html( $settings, $key, $ctx['record'], $extra );
	if ( '' !== $label ) {
		$classes[] = 'agend-field--labelled';
		if ( 'yes' === ( $settings['label_block_display'] ?? '' ) ) {
			$classes[] = 'agend-field--label-block';
		}
	}

	return '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '">' . $label . $inner . '</' . $tag . '>';
}
