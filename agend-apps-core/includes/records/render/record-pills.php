<?php
/**
 * Server render of the record pills surface: a record's categories, tags or
 * other terms, one styled pill per term, for use inside card and detail
 * templates.
 *
 * Page-builder agnostic: the same markup whichever editor placed the
 * surface. An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * The pill count follows the record, so an event with three tags renders
 * three pills. An Agend Field styled to look like a pill cannot do that: it
 * renders one element holding a joined list, so three tags become one wide
 * chip reading "a, b, c".
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the whole "does this pills surface render, and if not why"
 * decision in one pass: the record context in scope, which terms field the
 * settings name, whether that field applies to the record type in scope, and
 * the terms themselves once `max_items` is applied.
 *
 * `renders` is false with reason '' for the same silent "nothing more
 * specific to say" treatment agend_apps_records_filter_render_reason() gives
 * its own silent cases: no record in scope at all, so there is nothing to
 * read terms from.
 *
 * A field the registry does not consider applicable to the record type in
 * scope, and a record that resolves to zero terms once truncated to
 * `max_items` (a listing with no tags, say), are the two cases with something
 * worth saying to an author, so each carries its own reason.
 *
 * agend_apps_records_record_pills_render_reason() and
 * agend_apps_records_render_record_pills() both read this single source of
 * truth instead of restating its conditions, so the two cannot drift apart.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-pills' )).
 * @param array $opts     Passed straight through to
 *                        agend_apps_records_resolve_record_context(): 'preview'
 *                        and 'preview_type'.
 * @return array{renders: bool, reason: string, ctx: array, key: string, terms: string[]}
 *               `reason` is '', 'field_not_applicable' or 'no_terms'.
 *               `renders` is false whenever `reason` is non-'', but also for
 *               the silent case above, so a caller checks `renders` to decide
 *               whether to render and `reason` only to decide whether there is
 *               something worth telling an author.
 */
function agend_apps_records_record_pills_resolve( array $settings, array $opts = array() ): array {
	$ctx = agend_apps_records_resolve_record_context( $opts );

	if ( '' === $ctx['type'] ) {
		return array( 'renders' => false, 'reason' => '', 'ctx' => $ctx, 'key' => '', 'terms' => array() );
	}

	$key = (string) ( $settings['field'] ?? 'common:category' );
	if ( ! agend_apps_records_field_applies( $key, $ctx['type'] ) ) {
		return array( 'renders' => false, 'reason' => 'field_not_applicable', 'ctx' => $ctx, 'key' => $key, 'terms' => array() );
	}

	$terms = agend_apps_records_field_terms( $key, $ctx['type'], $ctx['record'], $ctx['extra'] );
	$max   = (int) ( $settings['max_items'] ?? 0 );
	if ( $max > 0 ) {
		$terms = array_slice( $terms, 0, $max );
	}

	if ( empty( $terms ) ) {
		return array( 'renders' => false, 'reason' => 'no_terms', 'ctx' => $ctx, 'key' => $key, 'terms' => array() );
	}

	return array( 'renders' => true, 'reason' => '', 'ctx' => $ctx, 'key' => $key, 'terms' => $terms );
}

/**
 * Why a pills surface with these settings renders nothing, or '' when it
 * renders.
 *
 * Moved out here, as a stable reason code rather than a message, so a caller
 * other than the Elementor widget (a block's own editor) can surface the same
 * diagnostic without duplicating the conditions that trigger it; the widget
 * still owns the translated copy and the decision to show it at all
 * (render_editor_notice() is an editor-only concern and stays there).
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-pills' )).
 * @param array $opts     Passed straight through to
 *                        agend_apps_records_resolve_record_context().
 * @return string '', 'field_not_applicable' or 'no_terms'.
 */
function agend_apps_records_record_pills_render_reason( array $settings, array $opts = array() ): string {
	return agend_apps_records_record_pills_resolve( $settings, $opts )['reason'];
}

/**
 * The label element, or '' when the surface is not showing one.
 *
 * @param array  $s      Surface settings.
 * @param string $key    Terms field key.
 * @param array  $record The record.
 * @param array  $extra  Render context.
 * @return string Escaped HTML.
 */
function agend_apps_records_record_pills_label_html( array $s, string $key, array $record, array $extra ): string {
	if ( 'yes' !== ( $s['show_label'] ?? '' ) ) {
		return '';
	}

	$text = trim( (string) ( $s['label_text'] ?? '' ) );
	if ( '' === $text ) {
		$text = agend_apps_records_field_label( $key, $record, $extra );
	}

	return '' === $text ? '' : '<span class="agend-pills__label">' . esc_html( $text ) . '</span>';
}

/**
 * Renders the record pills surface: one pill per term of the terms field the
 * settings name, from the record in scope.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-pills' )).
 * @param array $opts     Passed straight through to
 *                        agend_apps_records_resolve_record_context(): 'preview'
 *                        (bool, default false) and 'preview_type' (string).
 * @return string The rendered markup, or '' when the surface renders nothing
 *                (see agend_apps_records_record_pills_render_reason() for why,
 *                when a caller needs to know).
 */
function agend_apps_records_render_record_pills( array $settings, array $opts = array() ): string {
	$resolved = agend_apps_records_record_pills_resolve( $settings, $opts );
	if ( ! $resolved['renders'] ) {
		return '';
	}
	// agend_apps_records_record_pills_resolve() only ever returns renders =>
	// true alongside a non-empty terms list, so it is safe to iterate as-is.
	$ctx   = $resolved['ctx'];
	$key   = $resolved['key'];
	$terms = $resolved['terms'];

	$link = ( 'yes' === ( $settings['link_to_detail'] ?? '' ) && empty( $ctx['extra']['in_card_link'] ) )
		? (string) ( agend_apps_records_field_value( 'common:detail_url', $ctx['type'], $ctx['record'], $ctx['extra'] ) ?? '' )
		: '';

	$label   = agend_apps_records_record_pills_label_html( $settings, $key, $ctx['record'], $ctx['extra'] );
	$classes = array( 'agend-pills' );
	if ( '' !== $label ) {
		$classes[] = 'agend-pills--labelled';
		if ( 'yes' === ( $settings['label_block_display'] ?? '' ) ) {
			$classes[] = 'agend-pills--label-block';
		}
	}

	$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
	$html .= $label;
	foreach ( $terms as $term ) {
		if ( '' !== $link && '#' !== $link ) {
			$html .= '<a class="agend-pill" href="' . esc_url( $link ) . '">' . esc_html( $term ) . '</a>';
		} else {
			$html .= '<span class="agend-pill">' . esc_html( $term ) . '</span>';
		}
	}
	$html .= '</div>';

	return $html;
}
