<?php
/**
 * Server render of the catalogue filter surface.
 *
 * Page-builder agnostic: the same markup and client config whichever editor
 * placed the surface. An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * The widget emits a labelled shell carrying its configuration; the catalogue
 * script (assets/js/filters.js) builds the real control inside it at runtime
 * and wires changes to the catalogue's own filter state. `$opts['preview']`
 * draws the same stand-in control markup an Elementor editor showed before
 * this moved out of the widget, so any editor (Elementor's, or a block's) can
 * offer a designer something to see and style.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';

/**
 * Resolves the whole "does this filter render, and if not why" decision in
 * one pass, from a type and key already parsed out of the `filter` setting.
 *
 * The four conditions that make a filter render nothing -- wrong catalogue,
 * unconfigured, missing custom field key, choices-only with none defined --
 * are stated exactly once here. agend_apps_records_filter_render_reason()
 * and agend_apps_records_render_filter() both read this single source of
 * truth instead of restating them, so the two can no longer drift apart.
 *
 * The custom field key check runs BEFORE agend_apps_records_filter_config()
 * rather than after, on purpose (SPEC-INFRA-20260907-gutenberg-block-colours-
 * and-surfaces US-4.1 follow-up): that function already returns null for a
 * `needs_key` descriptor with an empty key (filters.php), which used to make
 * 'unconfigured' win over the more specific 'missing_field_key' every time --
 * an author who picked a custom field filter and left the key blank saw "not
 * configured yet" instead of "enter the custom field key". Checking it here
 * first is a deliberate change to WHICH notice an author sees; the rendered
 * front end is unchanged, because both reasons already render nothing.
 *
 * Once both of agend_apps_records_filter_config()'s own null-return
 * conditions are ruled out above, calling it is guaranteed to return a
 * config, which is what lets the render path skip its own null check.
 *
 * @param string $type     Record type, already parsed from the `filter` setting.
 * @param string $key      Filter key, already parsed from the `filter` setting.
 * @param string $context  The record type in scope, from
 *                         Agend_Apps_Records_Filter_Context::type(), or ''
 *                         outside a catalogue's filter template.
 * @param array  $settings Surface settings (see agend_apps_records_surface_schema( 'filter' )).
 * @return array{reason: string, config: ?array} `reason` is '', or one of
 *               'wrong_catalogue', 'unconfigured', 'missing_field_key',
 *               'no_choices'; `config` is non-null if and only if `reason`
 *               is ''.
 */
function agend_apps_records_filter_resolve( string $type, string $key, string $context, array $settings ): array {
	if ( '' !== $context && $context !== $type ) {
		return array( 'reason' => 'wrong_catalogue', 'config' => null );
	}

	$descriptor = agend_apps_records_filter_descriptor( $type, $key );
	if ( null === $descriptor ) {
		return array( 'reason' => 'unconfigured', 'config' => null );
	}

	if ( ! empty( $descriptor['needs_key'] ) && '' === trim( (string) ( $settings['custom_field_key'] ?? '' ) ) ) {
		return array( 'reason' => 'missing_field_key', 'config' => null );
	}

	$config = agend_apps_records_filter_config( $type, $key, $settings );

	if ( ! empty( $config['choicesOnly'] ) && empty( $config['values'] ) ) {
		return array( 'reason' => 'no_choices', 'config' => null );
	}

	return array( 'reason' => '', 'config' => $config );
}

/**
 * Why a filter with these settings renders nothing, or '' when it renders.
 *
 * The Elementor widget's render() used to compute this inline, alongside the
 * translated notice text it shows for each case. Moved out here, as a stable
 * reason code rather than a message, so a caller other than that widget (a
 * block's own editor) can surface the same diagnostic without duplicating
 * the conditions that trigger it; the widget still owns the translated copy
 * and the decision to show it at all (render_editor_notice() is an
 * editor-only concern and stays there).
 *
 * Not every silent case is covered: a `filter` setting with no colon also
 * renders nothing but carries no more specific reason than that -- it
 * returns '' here too, same as a filter that renders fine, because there is
 * nothing to tell an author that "render nothing" does not already say. The
 * render path additionally renders nothing outside a catalogue's filter
 * template when it is not a preview; that is not a reason either, since a
 * preview of the very same settings renders fine (see
 * agend_apps_records_render_filter()).
 *
 * A Range brackets filter with nothing to offer is the same kind of silent
 * case. Live, "Custom brackets" with no usable line renders nothing, and an
 * "Agend" filter whose facet has no presets is hidden by the script once it
 * has asked (the server cannot know that without a gateway call on every
 * page view). In an editor preview the same cases draw their explanation in
 * place of the control, from this renderer, so no reason code is needed and
 * neither adapter has a message of its own to keep in step.
 *
 * @param array  $settings Surface settings (see agend_apps_records_surface_schema( 'filter' )).
 * @param string $context  The record type in scope, from
 *                         Agend_Apps_Records_Filter_Context::type(), or ''
 *                         outside a catalogue's filter template.
 * @return string '', or one of 'wrong_catalogue', 'unconfigured',
 *                'missing_field_key', 'no_choices'.
 */
function agend_apps_records_filter_render_reason( array $settings, string $context ): string {
	$selected = (string) ( $settings['filter'] ?? '' );
	if ( false === strpos( $selected, ':' ) ) {
		return '';
	}
	list( $type, $key ) = explode( ':', $selected, 2 );

	return agend_apps_records_filter_resolve( $type, $key, $context, $settings )['reason'];
}

/**
 * Stand-in values for a control whose real list is fetched at render time.
 *
 * A facet or endpoint-backed filter has no values until a visitor loads the
 * page, so a preview shows plausible ones at a realistic width.
 *
 * @param array $config The filter config (see agend_apps_records_filter_config()).
 * @return array<int, string> Option labels.
 */
function agend_apps_records_filter_preview_values( array $config ): array {
	if ( ! empty( $config['values'] ) ) {
		return array_map(
			static function ( $entry ) {
				return (string) $entry['label'];
			},
			$config['values']
		);
	}

	$label = '' !== $config['label'] ? $config['label'] : __( 'Value', 'agend-apps-core' );
	return array(
		/* translators: %s: the filter's label, e.g. Category. */
		sprintf( __( 'Example %s one', 'agend-apps-core' ), strtolower( $label ) ),
		sprintf( __( 'Example %s two', 'agend-apps-core' ), strtolower( $label ) ),
		sprintf( __( 'Example %s three', 'agend-apps-core' ), strtolower( $label ) ),
	);
}

/**
 * The "no selection" label of a choice control.
 *
 * @param array $config The filter config.
 * @return string
 */
function agend_apps_records_filter_any_label( array $config ): string {
	if ( '' !== $config['anyLabel'] ) {
		return $config['anyLabel'];
	}

	/* translators: %s: the filter's label, e.g. Category. "Any" rather than
	"All" so a singular label still reads correctly. */
	return sprintf( __( 'Any %s', 'agend-apps-core' ), $config['label'] );
}

/**
 * Text for a radius choice, e.g. "Within 15 km".
 *
 * @param float $km Radius in kilometres.
 * @return string
 */
function agend_apps_records_filter_radius_label( float $km ): string {
	/* translators: %s: a radius in kilometres. */
	return sprintf( __( 'Within %s km', 'agend-apps-core' ), agend_apps_records_filter_number( $km ) );
}

/**
 * A number without a trailing ".0", for radius values.
 *
 * @param float $value The number.
 * @return string
 */
function agend_apps_records_filter_number( float $value ): string {
	return floor( $value ) === $value ? (string) (int) $value : (string) $value;
}

/**
 * The Location filter's markup, shared by the live stand-in and the preview.
 *
 * Mirrors buildLocation() in assets/js/filters.js node for node: a place
 * box and its Search button, the "Use my location" button, the radius
 * dropdown (only when there is more than one choice), a status line for
 * lookup errors, and the chip that names the active location.
 *
 * @param array $config   The filter config (see agend_apps_records_filter_config()).
 * @param bool  $disabled Draw every control disabled, as the live stand-in does.
 * @return string
 */
function agend_apps_records_render_filter_location_control( array $config, bool $disabled ): string {
	$location = $config['location'];
	$off      = $disabled ? ' disabled' : '';
	$row      = 'row' === ( $location['layout'] ?? '' );
	$html     = '<div class="agend-filter__location' . ( $row ? ' agend-filter__location--row' : '' ) . ( $disabled ? ' agend-filter__placeholder' : '' ) . '"' . ( $disabled ? ' aria-busy="true"' : '' ) . '>';

	// The one-row layout needs both rows inside one element it can wrap as a
	// line, with the status line and chip left outside it, beneath. Stacked
	// has no such element, so its markup is unchanged.
	if ( $row ) {
		$html .= '<div class="agend-filter__location-fields">';
	}

	if ( $location['search'] ) {
		$placeholder = '' !== $config['placeholder'] ? $config['placeholder'] : __( 'Suburb or postcode', 'agend-apps-core' );
		$html       .= '<div class="agend-filter__location-row agend-filter__location-row--search">';
		$html       .= '<input type="search" class="agend-filter__location-input" placeholder="' . esc_attr( $placeholder ) . '" aria-label="' . esc_attr( $placeholder ) . '" maxlength="200"' . $off . ' />';
		$html       .= '<button type="button" class="agend-filter__button agend-filter__location-search"' . $off . '>' . esc_html( '' !== $location['buttonText'] ? $location['buttonText'] : __( 'Search', 'agend-apps-core' ) ) . '</button>';
		$html       .= '</div>';
	}

	$html .= '<div class="agend-filter__location-row agend-filter__location-row--options">';
	if ( $location['locate'] ) {
		$html .= '<button type="button" class="agend-filter__button agend-filter__location-locate"' . $off . '>' . esc_html( '' !== $location['locateText'] ? $location['locateText'] : __( 'Use my location', 'agend-apps-core' ) ) . '</button>';
	}
	if ( count( $location['radii'] ) > 1 ) {
		$html .= '<select class="agend-filter__location-radius" aria-label="' . esc_attr__( 'Search radius', 'agend-apps-core' ) . '"' . $off . '>';
		foreach ( $location['radii'] as $radius ) {
			$html .= '<option value="' . esc_attr( agend_apps_records_filter_number( $radius ) ) . '"' . ( $radius === $location['radius'] ? ' selected' : '' ) . '>' . esc_html( agend_apps_records_filter_radius_label( $radius ) ) . '</option>';
		}
		$html .= '</select>';
	}
	$html .= '</div>';

	if ( $row ) {
		$html .= '</div>';
	}

	$html .= '<p class="agend-filter__location-status" role="status" aria-live="polite" hidden></p>';
	$html .= '<div class="agend-filter__location-chip" hidden><span class="agend-filter__location-chip-text"></span><button type="button" class="agend-filter__location-clear" aria-label="' . esc_attr__( 'Remove location', 'agend-apps-core' ) . '">&times;</button></div>';
	$html .= '</div>';

	return $html;
}

/**
 * The List / Map switch's markup, shared by the live stand-in and the preview.
 *
 * Two buttons in the filter's own button styling, so the Style tab's button
 * colours dress it with no settings of its own.
 *
 * @param array $config   The filter config.
 * @param bool  $disabled Draw both buttons disabled, as the live stand-in does.
 * @return string
 */
function agend_apps_records_render_filter_view_control( array $config, bool $disabled ): string {
	$view    = $config['view'];
	$off     = $disabled ? ' disabled' : '';
	$buttons = array(
		'list' => '' !== $view['listLabel'] ? $view['listLabel'] : __( 'List', 'agend-apps-core' ),
		'map'  => '' !== $view['mapLabel'] ? $view['mapLabel'] : __( 'Map', 'agend-apps-core' ),
	);

	$classes = 'agend-filter__options agend-filter__view';
	if ( 'joined' === $view['layout'] ) {
		$classes .= ' agend-filter__view--joined';
	}
	if ( $disabled ) {
		$classes .= ' agend-filter__placeholder';
	}

	$html = '<div class="' . esc_attr( $classes ) . '" role="group" aria-label="' . esc_attr( $config['label'] ) . '">';
	foreach ( $buttons as $value => $label ) {
		$on    = $value === $view['default'];
		$html .= '<button type="button" class="agend-filter__button' . ( $on ? ' is-active' : '' ) . '" data-agend-view="' . esc_attr( $value ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '"' . $off . '>' . esc_html( $label ) . '</button>';
	}
	$html .= '</div>';

	return $html;
}

/**
 * The brackets a facet carries for one custom field, read from the gateway.
 *
 * Only an editor preview asks: a visitor's script reads the same facet from
 * the page's own request, and a live render must never cost a gateway call.
 * The facets endpoint is entitlement-scoped, so a field the person editing may
 * not read comes back absent and previews as having no brackets.
 *
 * @param string $field_key The custom field key.
 * @return array The validated presets, empty when the facet has none, cannot be read, or the API is unavailable.
 */
function agend_apps_records_filter_range_brackets_agend_presets( string $field_key ): array {
	if ( '' === $field_key || ! function_exists( 'agend_apps_directory_get_facets' ) ) {
		return array();
	}

	$facet    = 'custom.' . $field_key;
	$response = agend_apps_directory_get_facets( array( $facet ) );
	if ( is_wp_error( $response ) || ! is_array( $response ) || ! is_array( $response['data'] ?? null ) ) {
		return array();
	}

	$entry = $response['data'][ $facet ] ?? null;
	return is_array( $entry ) ? agend_apps_records_filter_range_brackets_presets( $entry['presets'] ?? null ) : array();
}

/**
 * What a Range brackets preview has to draw: which brackets, where they came
 * from, and which of the author's lines were skipped.
 *
 * @param array $config   The filter config (see agend_apps_records_filter_config()).
 * @param array $settings Surface settings, for the raw textarea the skipped lines are read from.
 * @return array{origin: string, brackets: array, issues: array}
 */
function agend_apps_records_filter_range_brackets_preview_view( array $config, array $settings ): array {
	$opts    = $config['rangeBrackets'];
	$presets = 'custom' === $opts['source'] ? array() : agend_apps_records_filter_range_brackets_agend_presets( (string) $config['fieldKey'] );
	$parsed  = agend_apps_records_filter_range_brackets_parse( $settings['range_brackets_custom'] ?? '' );

	return array_merge(
		agend_apps_records_filter_range_brackets_resolve( $opts['source'], $presets, $opts['custom'] ),
		array( 'issues' => $parsed['issues'] )
	);
}

/**
 * The editor-only notes under a Range brackets preview, as list markup.
 *
 * @param array $config The filter config.
 * @param array $view   See agend_apps_records_filter_range_brackets_preview_view().
 * @return string '' when there is nothing to say.
 */
function agend_apps_records_filter_range_brackets_notes( array $config, array $view ): string {
	$opts  = $config['rangeBrackets'];
	$notes = array();

	if ( 'none' === $view['origin'] ) {
		$notes[] = 'custom' === $opts['source']
			? __( 'No brackets are configured. Add at least one line under Custom brackets, for example Under 10,000 | | 9999. Visitors see nothing until you do.', 'agend-apps-core' )
			: __( 'No brackets are configured. Agend has none for this field (or you cannot read it here) and no custom brackets are set, so visitors see nothing. Set brackets up in Agend, or add them under Custom brackets.', 'agend-apps-core' );
	} elseif ( 'custom' === $view['origin'] && 'custom' !== $opts['source'] ) {
		$notes[] = __( 'Agend has no brackets for this field, so the custom brackets are used.', 'agend-apps-core' );
	}

	$messages = array(
		/* translators: %d: a line number in the Custom brackets box. */
		'format' => __( 'Line %d was skipped: write it as Label | min | max.', 'agend-apps-core' ),
		/* translators: %d: a line number in the Custom brackets box. */
		'label'  => __( 'Line %d was skipped: it has no label.', 'agend-apps-core' ),
		/* translators: %d: a line number in the Custom brackets box. */
		'number' => __( 'Line %d was skipped: a bound must be a plain number such as 9999 or 12.5.', 'agend-apps-core' ),
		/* translators: %d: a line number in the Custom brackets box. */
		'open'   => __( 'Line %d was skipped: give a minimum, a maximum, or both.', 'agend-apps-core' ),
		/* translators: %d: a line number in the Custom brackets box. */
		'order'  => __( 'Line %d was skipped: the minimum is above the maximum.', 'agend-apps-core' ),
		/* translators: 1: a line number in the Custom brackets box, 2: the most brackets allowed. */
		'limit'  => __( 'Line %1$d and any after it were skipped: at most %2$d brackets are used.', 'agend-apps-core' ),
	);

	// A long paste of bad lines should not bury the preview.
	$shown = array_slice( $view['issues'], 0, 5 );
	foreach ( $shown as $issue ) {
		if ( isset( $messages[ $issue['code'] ] ) ) {
			$notes[] = sprintf( $messages[ $issue['code'] ], $issue['line'], AGEND_APPS_RECORDS_RANGE_BRACKETS_MAX );
		}
	}
	if ( count( $view['issues'] ) > count( $shown ) ) {
		/* translators: %d: how many more custom bracket lines were skipped. */
		$notes[] = sprintf( __( '%d more lines were skipped.', 'agend-apps-core' ), count( $view['issues'] ) - count( $shown ) );
	}

	if ( empty( $notes ) ) {
		return '';
	}

	$html = '<ul class="agend-filter__notice" role="note">';
	foreach ( $notes as $note ) {
		$html .= '<li>' . esc_html( $note ) . '</li>';
	}
	return $html . '</ul>';
}

/**
 * The Range brackets control's markup, shared by the live stand-in and the preview.
 *
 * Mirrors buildRangeBrackets() in assets/js/filters.js node for node. The first
 * choice is always "Any", selected; a visitor's script fills in the rest, so
 * the live stand-in draws "Any" alone, disabled and busy, in the final shape of
 * the chosen display.
 *
 * @param array $config   The filter config (see agend_apps_records_filter_config()).
 * @param array $brackets The brackets to draw after "Any" (none for the live stand-in).
 * @param bool  $disabled Draw it disabled and busy, as the live stand-in does.
 * @return string
 */
function agend_apps_records_render_filter_range_brackets_control( array $config, array $brackets, bool $disabled ): string {
	$opts    = $config['rangeBrackets'];
	$labels  = array_merge( array( $opts['anyLabel'] ), array_column( $brackets, 'label' ) );
	$off     = $disabled ? ' disabled' : '';
	$busy    = $disabled ? ' aria-busy="true"' : '';
	$holder  = $disabled ? ' agend-filter__placeholder' : '';
	$aria    = ' aria-label="' . esc_attr( $config['label'] ) . '"';
	$display = $opts['display'];

	if ( 'buttons' === $display ) {
		$html = '<div class="agend-filter__options agend-filter__brackets agend-filter__brackets--buttons' . $holder . '" role="group"' . $aria . $busy . '>';
		foreach ( $labels as $index => $label ) {
			$html .= '<button type="button" class="agend-filter__button' . ( 0 === $index ? ' is-active' : '' ) . '" aria-pressed="' . ( 0 === $index ? 'true' : 'false' ) . '"' . $off . '>' . esc_html( $label ) . '</button>';
		}
		return $html . '</div>';
	}

	if ( 'radio' === $display ) {
		$name = 'agend-filter-brackets-' . sanitize_html_class( (string) $config['fieldKey'] );
		$html = '<div class="agend-filter__options agend-filter__brackets agend-filter__brackets--radio' . $holder . '" role="radiogroup"' . $aria . $busy . '>';
		foreach ( $labels as $index => $label ) {
			$html .= '<label class="agend-filter__option"><input type="radio" name="' . esc_attr( $name ) . '"' . ( 0 === $index ? ' checked' : '' ) . $off . ' /><span>' . esc_html( $label ) . '</span></label>';
		}
		return $html . '</div>';
	}

	$html = '<select class="agend-filter__brackets' . $holder . '"' . $aria . $busy . $off . '>';
	foreach ( $labels as $label ) {
		$html .= '<option>' . esc_html( $label ) . '</option>';
	}
	return $html . '</select>';
}

/**
 * A Range brackets preview: the control with the brackets it would offer, and
 * the notes an author needs, or only the notes when there is nothing to offer.
 *
 * @param array $config   The filter config.
 * @param array $settings Surface settings.
 * @return string
 */
function agend_apps_records_render_filter_range_brackets_preview( array $config, array $settings ): string {
	$view = agend_apps_records_filter_range_brackets_preview_view( $config, $settings );
	$html = empty( $view['brackets'] )
		? ''
		: agend_apps_records_render_filter_range_brackets_control( $config, $view['brackets'], false );

	return $html . agend_apps_records_filter_range_brackets_notes( $config, $view );
}

/**
 * The share control's button, shared by the live stand-in and the preview.
 *
 * @param array $config   The filter config.
 * @param bool  $disabled Draw it disabled, as the live stand-in does.
 * @return string
 */
function agend_apps_records_render_filter_share_control( array $config, bool $disabled ): string {
	return '<button type="button" class="agend-filter__button agend-filter__share' . ( $disabled ? ' agend-filter__placeholder" disabled' : '"' ) . '>' . esc_html( $config['share']['buttonText'] ) . '</button>';
}

/**
 * Builds the disabled control a live page shows until the runtime wires it.
 *
 * A facet or endpoint-backed list arrives one request after the page does,
 * and a control that appears out of nowhere a few seconds in reads as broken.
 * So the real control node is drawn here, disabled, in its final shape and
 * with its "Any" label; assets/js/filters.js adopts that very node, fills its
 * options, attaches its listeners and enables it. Nothing is replaced, so the
 * node a designer styled is the node a visitor uses.
 *
 * @param array $config The filter config.
 * @return string
 */
function agend_apps_records_render_filter_placeholder_control( array $config ): string {
	$any = agend_apps_records_filter_any_label( $config );

	switch ( $config['control'] ) {
		case 'location':
			return agend_apps_records_render_filter_location_control( $config, true );

		case 'view':
			return agend_apps_records_render_filter_view_control( $config, true );

		case 'share':
			return agend_apps_records_render_filter_share_control( $config, true );

		case 'search':
			return sprintf(
				'<input type="search" class="agend-filter__placeholder" placeholder="%s" disabled />',
				esc_attr( '' !== $config['placeholder'] ? $config['placeholder'] : $config['label'] )
			);

		case 'date':
			return '<input type="date" class="agend-filter__placeholder" disabled />';

		case 'reset':
			return '<button type="button" class="agend-filter__button agend-filter__reset agend-filter__placeholder" disabled>' . esc_html( '' !== $config['label'] ? $config['label'] : __( 'Clear filters', 'agend-apps-core' ) ) . '</button>';

		case 'range_brackets':
			return agend_apps_records_render_filter_range_brackets_control( $config, array(), true );

		case 'range':
			return '<div class="agend-filter__range agend-filter__placeholder" aria-busy="true"><input type="number" placeholder="' . esc_attr__( 'Min', 'agend-apps-core' ) . '" disabled /><input type="number" placeholder="' . esc_attr__( 'Max', 'agend-apps-core' ) . '" disabled /></div>';

		case 'checkboxes':
			return '<div class="agend-filter__options agend-filter__placeholder" aria-busy="true"></div>';

		case 'buttons':
			return '<div class="agend-filter__options agend-filter__placeholder" aria-busy="true"><button type="button" class="agend-filter__button is-active" aria-pressed="true" disabled>' . esc_html( $any ) . '</button></div>';

		default:
			return '<select class="agend-filter__placeholder" aria-busy="true" disabled><option>' . esc_html( $any ) . '</option></select>';
	}
}

/**
 * Builds the control markup the runtime would build, for a preview.
 *
 * Mirrors the markup in assets/js/filters.js element for element and class
 * for class, so every style control on the filter surface lands on the same
 * nodes in a preview as on the live page.
 *
 * @param array $config   The filter config.
 * @param array $settings Surface settings. Only Range brackets reads them, for
 *                        the lines of the author's list it had to skip.
 * @return string
 */
function agend_apps_records_render_filter_preview_control( array $config, array $settings = array() ): string {
	$any = agend_apps_records_filter_any_label( $config );

	switch ( $config['control'] ) {
		case 'location':
			return agend_apps_records_render_filter_location_control( $config, false );

		case 'view':
			return agend_apps_records_render_filter_view_control( $config, false );

		case 'share':
			// The front end decides whether the control shows, so the editor
			// says when it will not.
			return agend_apps_records_render_filter_share_control( $config, false )
				. '<p class="agend-filter__notice" role="note">' . esc_html__( 'Shares this page\'s directory search. Hidden on a page with more than one directory catalogue.', 'agend-apps-core' ) . '</p>';

		case 'search':
			return sprintf(
				'<input type="search" placeholder="%s" />',
				esc_attr( '' !== $config['placeholder'] ? $config['placeholder'] : $config['label'] )
			);

		case 'date':
			return '<input type="date" />';

		case 'range_brackets':
			return agend_apps_records_render_filter_range_brackets_preview( $config, $settings );

		case 'range':
			return '<div class="agend-filter__range"><input type="number" placeholder="' . esc_attr__( 'Min', 'agend-apps-core' ) . '" /><input type="number" placeholder="' . esc_attr__( 'Max', 'agend-apps-core' ) . '" /></div>';

		case 'reset':
			return '<button type="button" class="agend-filter__button agend-filter__reset">' . esc_html( '' !== $config['label'] ? $config['label'] : __( 'Clear filters', 'agend-apps-core' ) ) . '</button>';

		case 'checkboxes':
			$html = '<div class="agend-filter__options">';
			foreach ( agend_apps_records_filter_preview_values( $config ) as $value ) {
				$html .= '<label class="agend-filter__option"><input type="checkbox" /><span>' . esc_html( $value ) . '</span></label>';
			}
			$html .= '</div>';
			return $html;

		case 'buttons':
			$html  = '<div class="agend-filter__options">';
			$html .= '<button type="button" class="agend-filter__button is-active" aria-pressed="true">' . esc_html( $any ) . '</button>';
			foreach ( agend_apps_records_filter_preview_values( $config ) as $value ) {
				$html .= '<button type="button" class="agend-filter__button" aria-pressed="false">' . esc_html( $value ) . '</button>';
			}
			$html .= '</div>';
			return $html;

		default:
			$html = '<select><option>' . esc_html( $any ) . '</option>';
			foreach ( agend_apps_records_filter_preview_values( $config ) as $value ) {
				$html .= '<option>' . esc_html( $value ) . '</option>';
			}
			$html .= '</select>';
			return $html;
	}
}

/**
 * The style hooks a filter's Style-tab settings put on its shell.
 *
 * Each set value becomes a CSS custom property plus a modifier class, and
 * assets/css/filters.css applies a property only under its class. The class
 * is what keeps an unset value from touching the control at all: a rule that
 * read an undefined custom property would reset the theme's own form styling
 * instead of leaving it alone.
 *
 * Colours go through agend_apps_records_colour_is_safe(); sizes must be one
 * of the choices the schema offers. Anything else is dropped.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'filter' )).
 * @return array{classes: string[], style: string}
 */
function agend_apps_records_filter_style( array $settings ): array {
	$colours = array(
		'field_background'         => array( 'bg', 'agend-filter--field-bg' ),
		'field_text_colour'        => array( 'text', 'agend-filter--field-text' ),
		'field_border_colour'      => array( 'border-colour', 'agend-filter--field-border-colour' ),
		'field_focus_colour'       => array( 'focus', 'agend-filter--field-focus' ),
		'button_colour'            => array( 'button', 'agend-filter--button-colour' ),
		'button_background'        => array( 'button-bg', 'agend-filter--button-bg' ),
		'button_active_background' => array( 'button-active-bg', 'agend-filter--button-active-bg' ),
		'button_active_text'       => array( 'button-active-text', 'agend-filter--button-active-text' ),
		'checkbox_colour'          => array( 'checkbox', 'agend-filter--checkbox-colour' ),
	);
	$sizes   = array(
		'field_border_width' => array( 'border-width', 'agend-filter--field-border-width', agend_apps_records_filter_border_width_options() ),
		'field_radius'       => array( 'radius', 'agend-filter--field-radius', agend_apps_records_filter_radius_options() ),
		'field_height'       => array( 'height', 'agend-filter--field-height', agend_apps_records_filter_height_options() ),
		'field_padding'      => array( 'padding-x', 'agend-filter--field-padding', agend_apps_records_filter_padding_options() ),
		'button_radius'      => array( 'button-radius', 'agend-filter--button-radius', agend_apps_records_filter_radius_options() ),
	);

	$classes = array();
	$vars    = array();

	foreach ( $colours as $key => $hook ) {
		$value = trim( (string) ( $settings[ $key ] ?? '' ) );
		if ( agend_apps_records_colour_is_safe( $value ) ) {
			$vars[]    = '--agend-filter-' . $hook[0] . ':' . $value;
			$classes[] = $hook[1];
		}
	}

	foreach ( $sizes as $key => $hook ) {
		$value = (string) ( $settings[ $key ] ?? '' );
		if ( '' === $value || ! array_key_exists( $value, $hook[2] ) ) {
			continue;
		}
		$vars[]    = '--agend-filter-' . $hook[0] . ':' . (int) $value . 'px';
		$classes[] = $hook[1];
	}

	if ( in_array( 'agend-filter--field-border-width', $classes, true ) && 'bottom' === ( $settings['field_border_sides'] ?? 'all' ) ) {
		$classes[] = 'agend-filter--field-border-bottom';
	}

	// Alignment needs no value of its own, only the class that applies it.
	$align = (string) ( $settings['button_align'] ?? '' );
	if ( in_array( $align, array( 'center', 'end' ), true ) ) {
		$classes[] = 'agend-filter--align-' . $align;
	}

	return array(
		'classes' => $classes,
		'style'   => implode( ';', $vars ),
	);
}

/**
 * The gate for a filter control, which inherits its catalogue's surface.
 *
 * The catalogue in scope wins (a filter inside its filter template), else the
 * type the filter itself declares, so a filter previewed in the editor still
 * names the catalogue it will need.
 *
 * @param array $settings Surface settings (`filter` is `type:key`).
 * @param bool  $editor   Whether an editor is drawing this.
 * @return string|null Null when open; see {@see agend_apps_records_surface_gate()}.
 */
function agend_apps_records_filter_gate( array $settings, bool $editor ): ?string {
	$context  = Agend_Apps_Records_Filter_Context::type();
	$selected = (string) ( $settings['filter'] ?? '' );
	$type     = '' !== $context ? $context : (string) strtok( $selected, ':' );

	return agend_apps_records_surface_gate( agend_apps_records_type_surface( $type ), $editor );
}

/**
 * Renders the catalogue filter surface: a labelled shell around the control
 * a catalogue's script fills in at runtime, or, with `$opts['preview']` on,
 * the stand-in control markup itself.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'filter' )).
 * @param array $opts     'preview' (bool, default false): draw the stand-in
 *                        control markup a preview shows for a control the
 *                        runtime otherwise builds, so a designer can style
 *                        and position exactly what a visitor will see.
 * @return string The rendered markup, or '' when the filter renders nothing
 *                (see agend_apps_records_filter_render_reason() for why, when
 *                a caller needs to know).
 */
function agend_apps_records_render_filter( array $settings, array $opts = array() ): string {
	$preview  = ! empty( $opts['preview'] );
	$selected = (string) ( $settings['filter'] ?? '' );
	if ( false === strpos( $selected, ':' ) ) {
		return '';
	}
	list( $type, $key ) = explode( ':', $selected, 2 );

	$gate = agend_apps_records_filter_gate( $settings, $preview );
	if ( null !== $gate ) {
		return $gate;
	}

	$context = Agend_Apps_Records_Filter_Context::type();

	// Outside a catalogue's filter template there is no record type in
	// scope. A preview still draws itself, using its own declared type, so a
	// designer can see and style the real control; live, it renders nothing.
	if ( '' === $context && ! $preview ) {
		return '';
	}

	$resolved = agend_apps_records_filter_resolve( $type, $key, $context, $settings );
	if ( '' !== $resolved['reason'] ) {
		return '';
	}
	// agend_apps_records_filter_resolve() only ever returns a reason of ''
	// alongside a non-null config, so this is safe to use as-is.
	$config = $resolved['config'];

	// "Custom brackets" with no usable line leaves a visitor nothing to choose
	// from, so there is no control to draw. An editor preview still draws, to
	// say why (agend_apps_records_filter_range_brackets_notes()).
	if ( ! $preview && 'range_brackets' === $config['control'] && 'custom' === $config['rangeBrackets']['source'] && empty( $config['rangeBrackets']['custom'] ) ) {
		return '';
	}

	$classes = 'agend-filter agend-filter--' . sanitize_html_class( $config['control'] ) . ' agend-filter--' . sanitize_html_class( $key );
	if ( $preview ) {
		$classes .= ' agend-filter--preview';
	}

	$style = agend_apps_records_filter_style( $settings );
	if ( ! empty( $style['classes'] ) ) {
		$classes .= ' ' . implode( ' ', $style['classes'] );
	}

	// A Subcategories of selected filter has nothing to offer until a
	// top-level category is chosen, so live it starts hidden and the script
	// shows it; a preview stays visible for the designer.
	$hidden = ! $preview && 'sub' === ( $config['categoryLevel'] ?? '' );

	if ( ! $preview ) {
		Agend_Apps_Records_Filter_Context::record( $config );
	}

	ob_start();
	echo '<div class="' . esc_attr( $classes ) . '"' . ( '' !== $style['style'] ? ' style="' . esc_attr( $style['style'] ) . '"' : '' ) . ' data-agend-filter="' . esc_attr( (string) wp_json_encode( $config ) ) . '"' . ( $hidden ? ' hidden' : '' ) . '>';
	// The share button names itself; a label over it would only repeat it.
	if ( $config['showLabel'] && '' !== $config['label'] && 'share' !== $config['control'] ) {
		echo '<span class="agend-filter__label">' . esc_html( $config['label'] ) . '</span>';
	}
	echo '<div class="agend-filter__control">';
	if ( $preview ) {
		// The live control is built by assets/js/filters.js once a catalogue
		// drives it. Nothing does that in a preview, so the same markup is
		// drawn here instead: a designer styles and positions exactly what a
		// visitor will see.
		echo agend_apps_records_render_filter_preview_control( $config, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper.
	} else {
		echo agend_apps_records_render_filter_placeholder_control( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper.
	}
	echo '</div>';
	echo '</div>';

	return (string) ob_get_clean();
}
