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
		case 'search':
			return sprintf(
				'<input type="search" class="agend-filter__placeholder" placeholder="%s" disabled />',
				esc_attr( '' !== $config['placeholder'] ? $config['placeholder'] : $config['label'] )
			);

		case 'date':
			return '<input type="date" class="agend-filter__placeholder" disabled />';

		case 'reset':
			return '<button type="button" class="agend-filter__button agend-filter__reset agend-filter__placeholder" disabled>' . esc_html( '' !== $config['label'] ? $config['label'] : __( 'Clear filters', 'agend-apps-core' ) ) . '</button>';

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
 * @param array $config The filter config.
 * @return string
 */
function agend_apps_records_render_filter_preview_control( array $config ): string {
	$any = agend_apps_records_filter_any_label( $config );

	switch ( $config['control'] ) {
		case 'search':
			return sprintf(
				'<input type="search" placeholder="%s" />',
				esc_attr( '' !== $config['placeholder'] ? $config['placeholder'] : $config['label'] )
			);

		case 'date':
			return '<input type="date" />';

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
		'button_active_background' => array( 'button-active-bg', 'agend-filter--button-active-bg' ),
		'button_active_text'       => array( 'button-active-text', 'agend-filter--button-active-text' ),
		'checkbox_colour'          => array( 'checkbox', 'agend-filter--checkbox-colour' ),
	);
	$sizes   = array(
		'field_border_width' => array( 'border-width', 'agend-filter--field-border-width', agend_apps_records_filter_border_width_options() ),
		'field_radius'       => array( 'radius', 'agend-filter--field-radius', agend_apps_records_filter_radius_options() ),
		'field_height'       => array( 'height', 'agend-filter--field-height', agend_apps_records_filter_height_options() ),
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

	return array(
		'classes' => $classes,
		'style'   => implode( ';', $vars ),
	);
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

	$classes = 'agend-filter agend-filter--' . sanitize_html_class( $config['control'] ) . ' agend-filter--' . sanitize_html_class( $key );
	if ( $preview ) {
		$classes .= ' agend-filter--preview';
	}

	$style = agend_apps_records_filter_style( $settings );
	if ( ! empty( $style['classes'] ) ) {
		$classes .= ' ' . implode( ' ', $style['classes'] );
	}

	ob_start();
	echo '<div class="' . esc_attr( $classes ) . '"' . ( '' !== $style['style'] ? ' style="' . esc_attr( $style['style'] ) . '"' : '' ) . ' data-agend-filter="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
	if ( $config['showLabel'] && '' !== $config['label'] ) {
		echo '<span class="agend-filter__label">' . esc_html( $config['label'] ) . '</span>';
	}
	echo '<div class="agend-filter__control">';
	if ( $preview ) {
		// The live control is built by assets/js/filters.js once a catalogue
		// drives it. Nothing does that in a preview, so the same markup is
		// drawn here instead: a designer styles and positions exactly what a
		// visitor will see.
		echo agend_apps_records_render_filter_preview_control( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper.
	} else {
		echo agend_apps_records_render_filter_placeholder_control( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper.
	}
	echo '</div>';
	echo '</div>';

	return (string) ob_get_clean();
}
