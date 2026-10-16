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

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';

// A restricted custom field is worded by agend_apps_records_gated_field_label(),
// shared with the built-in detail and kept in the formatters file.
require_once __DIR__ . '/../format.php';

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
		'date_format'        => $date_format,
		'price_free_label'   => (string) ( $s['price_free_label'] ?? '' ),
		'list_separator'     => (string) ( $s['list_separator'] ?? ', ' ),
		'list_max'           => (int) ( $s['list_max'] ?? 0 ),
		'list_show_rest'     => 'yes' === ( $s['show_rest'] ?? '' ),
		'list_overflow_text' => (string) ( $s['overflow_text'] ?? '' ),
		'list_collapse_text' => (string) ( $s['collapse_text'] ?? '' ),
		'list_rest_style'    => (string) ( $s['rest_style'] ?? '' ),
		'bool_true'          => (string) ( $s['bool_true'] ?? '' ),
		'bool_false'         => (string) ( $s['bool_false'] ?? '' ),
		'number_suffix'      => (string) ( $s['number_suffix'] ?? '' ),
		'truncate'           => (int) ( $s['truncate_chars'] ?? 0 ),
		'item_list'          => agend_apps_records_item_list_options( $s ),
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
 * The text shown in place of a custom field the viewer is not entitled to.
 *
 * The surface's own "Restricted text" when the author set one; otherwise the
 * label for the gateway's gate state, which sites can already reword through
 * the `agend_apps_records_gated_field_label` filter (the same wording the
 * built-in detail uses).
 *
 * @param array  $s     Surface settings.
 * @param string $state The gateway's gate state for the field.
 * @return string Plain text; '' when a filter blanked the default label, which
 *                the caller treats as "render nothing".
 */
function agend_apps_records_record_field_restricted_text( array $s, string $state ): string {
	$text = trim( (string) ( $s['restricted_text'] ?? '' ) );

	return '' !== $text ? $text : trim( agend_apps_records_gated_field_label( $state ) );
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
 * Where a linkable field's raw value links to.
 *
 * Only web addresses are linked, never another scheme a stored value happens
 * to carry, matching the built-in listing detail.
 *
 * @param string $kind The field kind.
 * @param string $raw  The raw value, before formatting.
 * @return string The unescaped href, or '' when the value cannot be linked.
 */
function agend_apps_records_record_field_value_href( string $kind, string $raw ): string {
	if ( 'phone' === $kind ) {
		return agend_apps_records_tel_href( $raw );
	}
	if ( 'url' === $kind ) {
		$url = esc_url_raw( trim( $raw ) );
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}
	return '';
}

/**
 * The formatted value as a link to itself (a website, or a tap-to-call
 * number) when the settings ask for one, or unchanged.
 *
 * Unchanged inside a card that is already one big link, where the custom link
 * text is dropped too: "Call us" that cannot be tapped tells a visitor less
 * than the number itself. "Link to detail page" keeps precedence.
 *
 * @param string $html     The formatted, escaped value.
 * @param array  $settings Surface settings.
 * @param string $key      Field key.
 * @param string $kind     Field kind.
 * @param array  $ctx      The resolved record context.
 * @param array  $extra    Render context.
 * @return string Escaped HTML.
 */
function agend_apps_records_record_field_linked_value( string $html, array $settings, string $key, string $kind, array $ctx, array $extra ): string {
	if ( 'yes' !== ( $settings['link_value'] ?? '' )
		|| 'yes' === ( $settings['link_to_detail'] ?? '' )
		|| ! empty( $extra['in_card_link'] )
		|| ! in_array( $key, agend_apps_records_linkable_field_keys(), true ) ) {
		return $html;
	}

	$raw  = agend_apps_records_field_value( $key, $ctx['type'], $ctx['record'], $extra );
	$href = is_scalar( $raw ) ? agend_apps_records_record_field_value_href( $kind, (string) $raw ) : '';
	if ( '' === $href ) {
		return $html;
	}

	$text   = trim( (string) ( $settings['link_text'] ?? '' ) );
	$label  = '' !== $text ? esc_html( $text ) : $html;
	$target = ( 'url' === $kind && 'yes' === ( $settings['link_new_tab'] ?? 'yes' ) )
		? ' target="_blank" rel="noopener noreferrer"'
		: '';

	return '<a class="agend-field__link agend-field__link--' . esc_attr( $kind ) . '" href="' . esc_url( $href ) . '"' . $target . '>' . $label . '</a>';
}

/**
 * Renders the record field surface: a single formatted value from the record
 * in scope, with the label, before/after text and detail link the settings
 * ask for.
 *
 * A record with no data for the field renders the fallback text. A custom
 * field the gateway withheld from the viewer renders the restricted text
 * instead, so "not entitled" and "nothing recorded" no longer read alike.
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
	$gate = agend_apps_records_record_gate( $settings, $opts );
	if ( null !== $gate ) {
		return $gate;
	}

	$resolved = agend_apps_records_record_field_resolve( $settings, $opts );
	if ( ! $resolved['renders'] ) {
		return '';
	}
	// agend_apps_records_record_field_resolve() only ever returns renders =>
	// true alongside a known key and kind, so both are safe to use as-is. The
	// kind is settled again below once the custom field key is known.
	$ctx  = $resolved['ctx'];
	$key  = $resolved['key'];
	$kind = $resolved['kind'];

	$extra                     = $ctx['extra'];
	$extra['custom_field_key'] = agend_apps_records_record_field_custom_field_key( $settings );
	$kind                      = agend_apps_records_field_kind_for_record( $key, $ctx['record'], $extra );

	// A custom field the gateway withheld from this viewer is not the same as
	// one with no data: it reads as restricted, never as the fallback text.
	$gate_state = 'common:custom_field' === $key
		? agend_apps_records_record_custom_field_gate( $ctx['record'], $extra['custom_field_key'] )
		: null;
	$is_gated   = null !== $gate_state;

	if ( $is_gated ) {
		$restricted = agend_apps_records_record_field_restricted_text( $settings, $gate_state );
		if ( '' === $restricted ) {
			return '';
		}
		$html = '<span class="agend-field__value agend-field__value--gated">' . esc_html( $restricted ) . '</span>';
	} else {
		$html = agend_apps_records_render_field( $key, $ctx['type'], $ctx['record'], $extra, agend_apps_records_record_field_format_options( $settings ) );
		if ( '' === $html ) {
			$fallback = trim( (string) ( $settings['fallback_text'] ?? '' ) );
			if ( '' === $fallback ) {
				return '';
			}
			$html = esc_html( $fallback );
		} else {
			$html = agend_apps_records_record_field_linked_value( $html, $settings, $key, $kind, $ctx, $extra );
		}
	}

	$before = (string) ( $settings['before_text'] ?? '' );
	$after  = (string) ( $settings['after_text'] ?? '' );
	if ( 'html' === $kind || 'item_list' === $kind ) {
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
	if ( ! in_array( $tag, array( 'div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) || 'item_list' === $kind ) {
		$tag = 'div';
	}

	$classes = array( 'agend-field', 'agend-field--' . $kind, 'agend-field--' . str_replace( ':', '-', $key ) );
	if ( $ctx['is_preview'] ) {
		$classes[] = 'agend-field--preview';
	}
	if ( $is_gated ) {
		$classes[] = 'agend-field--gated';
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
