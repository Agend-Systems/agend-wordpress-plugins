<?php
/**
 * Pure formatters and value normalisers shared by the SSR catalogue detail
 * pages, the Elementor "field" widgets, and the record surface renderers.
 *
 * Every function here takes scalars or arrays and returns a formatted string
 * (or, for agend_apps_records_record_timezone(), a DateTimeZone); none of them
 * echo or assemble a whole panel. Composite HTML fragments live in
 * fragments.php.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the timezone a catalogue record's dates should be displayed in.
 *
 * Falls back to the site timezone when the record has no `timezone` field or
 * the value is not a valid IANA identifier.
 *
 * @param array $record Catalogue record carrying an optional `timezone` field.
 * @return DateTimeZone The record's timezone, or the site timezone.
 */
function agend_apps_records_record_timezone( array $record ): DateTimeZone {
	if ( empty( $record['timezone'] ) ) {
		return wp_timezone();
	}
	try {
		return new DateTimeZone( (string) $record['timezone'] );
	} catch ( Exception $e ) {
		return wp_timezone();
	}
}

/**
 * Builds the default catalogue colour CSS variables for a server-rendered
 * detail.
 *
 * The virtual detail page is not tied to a specific widget instance, so the
 * plugin's default palette is applied. The prefix selects the widget family
 * ('agend-ev' for Events, 'agend-lms' for Courses).
 *
 * @param string $prefix The CSS-variable prefix (without the leading '--').
 * @return string The inline style declaration string.
 */
function agend_apps_records_ssr_colour_style( string $prefix ): string {
	return sprintf(
		'--%1$s-heading:#1E2A4A;--%1$s-body:#26304D;--%1$s-accent:#FF6B55;--%1$s-button:#FF6B55;--%1$s-button-text:#FFFFFF;--%1$s-card-radius:10px;',
		$prefix
	);
}

/**
 * Maps an event venue type to its display label.
 *
 * Mirrors TYPE_LABELS in assets/js/events-catalogue.js.
 *
 * @param string $type The venue type (physical, virtual, hybrid).
 * @return string The display label, or the raw type when unknown.
 */
function agend_apps_records_ssr_ev_type_label( string $type ): string {
	$labels = array(
		'physical' => __( 'In-Person', 'agend-apps-core' ),
		'virtual'  => __( 'Online', 'agend-apps-core' ),
		'hybrid'   => __( 'Hybrid', 'agend-apps-core' ),
	);
	return $labels[ $type ] ?? $type;
}

/**
 * Formats an event date range as "6 Jul 2026" or "6 Jul 2026 – 8 Jul 2026".
 *
 * Mirrors dateRange() in assets/js/events-catalogue.js, formatted in the
 * event's own timezone via wp_date().
 *
 * @param mixed             $start ISO 8601 start datetime.
 * @param mixed             $end   ISO 8601 end datetime, or empty.
 * @param DateTimeZone|null $tz    The event timezone (null = site timezone).
 * @return string The formatted range, or empty string.
 */
function agend_apps_records_ssr_ev_date_range( $start, $end, ?DateTimeZone $tz = null ): string {
	$start_ts = strtotime( (string) $start );
	if ( ! $start_ts ) {
		return '';
	}
	$start_str = wp_date( 'j M Y', $start_ts, $tz );
	$end_ts    = strtotime( (string) $end );
	if ( ! $end_ts ) {
		return $start_str;
	}
	$end_str = wp_date( 'j M Y', $end_ts, $tz );
	return $start_str === $end_str ? $start_str : $start_str . ' – ' . $end_str;
}

/**
 * Formats an event date and time, e.g. "Monday, 6 July 2026, 9:00 am – 5:00 pm
 * AEST".
 *
 * Mirrors dateTime() in assets/js/events-catalogue.js, formatted in the event's
 * own timezone via wp_date(), with the zone abbreviation appended so a time
 * shown in a zone other than the viewer's is unambiguous.
 *
 * @param mixed             $start ISO 8601 start datetime.
 * @param mixed             $end   ISO 8601 end datetime, or empty.
 * @param DateTimeZone|null $tz    The event timezone (null = site timezone).
 * @return string The formatted date and time, or empty string.
 */
function agend_apps_records_ssr_ev_date_time( $start, $end, ?DateTimeZone $tz = null ): string {
	$start_ts = strtotime( (string) $start );
	if ( ! $start_ts ) {
		return '';
	}
	$str    = wp_date( 'l, j F Y', $start_ts, $tz ) . ', ' . wp_date( 'g:i a', $start_ts, $tz );
	$end_ts = strtotime( (string) $end );
	if ( $end_ts ) {
		$str .= ' – ' . wp_date( 'g:i a', $end_ts, $tz );
	}
	$zone_label = wp_date( 'T', $start_ts, $tz );
	if ( '' !== (string) $zone_label ) {
		$str .= ' ' . $zone_label;
	}
	return $str;
}

/**
 * Formats a numeric price for the Events detail Tickets panel.
 *
 * Mirrors formatPrice() in assets/js/events-catalogue.js: null for a
 * non-numeric value, "FREE" for zero, "$X.XX" otherwise.
 *
 * @param mixed $value The raw price value (numeric or numeric string).
 * @return string|null The formatted price, or null when not numeric.
 */
function agend_apps_records_ssr_ev_format_price( $value ): ?string {
	if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
		return null;
	}
	$num = (float) $value;
	return 0.0 === $num ? __( 'FREE', 'agend-apps-core' ) : '$' . number_format( $num, 2, '.', '' );
}

/**
 * Formats a course duration in minutes as "2h 30m" / "45m" / "Self-paced".
 *
 * Mirrors formatDuration() in assets/js/courses-catalogue.js.
 *
 * @param mixed $minutes Total duration in minutes.
 * @return string The formatted duration.
 */
function agend_apps_records_ssr_lms_duration( $minutes ): string {
	$m = is_numeric( $minutes ) ? (int) $minutes : 0;
	if ( $m <= 0 ) {
		return __( 'Self-paced', 'agend-apps-core' );
	}
	$hours   = intdiv( $m, 60 );
	$remains = $m % 60;
	if ( $hours && $remains ) {
		return $hours . 'h ' . $remains . 'm';
	}
	return $hours ? $hours . 'h' : $remains . 'm';
}

/**
 * Maps a course difficulty to its display label.
 *
 * Mirrors DIFFICULTY_LABELS in assets/js/courses-catalogue.js.
 *
 * @param string $value The difficulty value.
 * @return string The display label, the raw value when unknown, or empty.
 */
function agend_apps_records_ssr_lms_difficulty( string $value ): string {
	if ( '' === $value ) {
		return '';
	}
	$labels = array(
		'beginner'     => __( 'Beginner', 'agend-apps-core' ),
		'intermediate' => __( 'Intermediate', 'agend-apps-core' ),
		'advanced'     => __( 'Advanced', 'agend-apps-core' ),
		'all_levels'   => __( 'All Levels', 'agend-apps-core' ),
	);
	return $labels[ $value ] ?? $value;
}

/**
 * Maps a course delivery mode to its display label.
 *
 * Mirrors DELIVERY_MODE_LABELS in assets/js/courses-catalogue.js (an unknown or
 * unset mode yields an empty label).
 *
 * @param string $value The delivery mode value.
 * @return string The display label, or empty string.
 */
function agend_apps_records_ssr_lms_mode( string $value ): string {
	$labels = array(
		'self_paced'  => __( 'Self-paced', 'agend-apps-core' ),
		'live_online' => __( 'Live Online', 'agend-apps-core' ),
		'in_person'   => __( 'In-Person', 'agend-apps-core' ),
		'blended'     => __( 'Blended', 'agend-apps-core' ),
	);
	return $labels[ $value ] ?? '';
}

/**
 * Formats a course price as "$120.00" or "Free".
 *
 * Mirrors priceLabel() in assets/js/courses-catalogue.js.
 *
 * @param array $course Course detail (gateway shape).
 * @return string The formatted price.
 */
function agend_apps_records_ssr_lms_price( array $course ): string {
	if ( ! empty( $course['is_free'] ) ) {
		return __( 'Free', 'agend-apps-core' );
	}
	$price = $course['base_price'] ?? null;
	$num   = is_numeric( $price ) ? (float) $price : 0.0;
	if ( $num <= 0.0 ) {
		return __( 'Free', 'agend-apps-core' );
	}
	// No thousands separator, matching the client priceLabel() (toFixed(2)) used
	// on the catalogue cards and the client-rendered detail.
	return '$' . number_format( $num, 2, '.', '' );
}

/**
 * Normalises a URL-valued `adapter` schema setting to a plain string.
 *
 * A `url`-shaped `adapter` field (see the `adapter` type note in schema.php)
 * is not one shape across page-builder adapters: Elementor's URL control
 * hands the widget `array( 'url' => ..., 'is_external' => ...,
 * 'nofollow' => ... )`, while a Gutenberg block stores the same setting as a
 * plain string. A renderer that reads a URL-valued setting normalises it once
 * through here rather than repeating the "is it an array or a string" check
 * inline; header-auth's `login_url` and memberships-catalogue's `success_url`
 * both need it.
 *
 * @param mixed $value Raw setting value: a string, an Elementor URL control
 *                      array, or unset/null.
 * @return string The URL, or '' when absent.
 */
function agend_apps_records_normalise_url_setting( $value ): string {
	if ( is_array( $value ) ) {
		return (string) ( $value['url'] ?? '' );
	}

	return null === $value ? '' : (string) $value;
}

/**
 * The `tel:` href for a phone number as people write it, keeping only the
 * digits and a `+` of the first number.
 *
 * An extension or a second number would otherwise run into the first and
 * dial something else, and the "(0)" written after a country code is not
 * dialled from abroad.
 *
 * @param string $phone The phone number, e.g. "(03) 9876-5432".
 * @return string The unescaped href, or '' when no digit is left to dial.
 */
function agend_apps_records_tel_href( string $phone ): string {
	$first  = (string) preg_split( '/(?<=\d)\s*(?:ext\.?|extn\.?|x|,|;|\/)/i', $phone, 2 )[0];
	$digits = (string) preg_replace( '/[^+\d]/', '', (string) preg_replace( '/^(\s*\+\d+)\s*\(0\)/', '$1', $first ) );

	return preg_match( '/\d/', $digits ) ? 'tel:' . $digits : '';
}

/**
 * The labels the gateway gave an option-backed custom field's stored value.
 *
 * A dropdown, multi-select, radio or checkbox-group entry stores option KEYS
 * in `value` (what filters match on, and what a URL parameter carries) and
 * carries the labels a person should read beside it in `value_label`: a
 * string next to a single `value`, a list in the same order next to a list of
 * values. The entry's own `label` is the FIELD's name, a different thing.
 *
 * This is for DISPLAY only. Anything that filters, compares or builds a query
 * from an entry keeps reading `value`.
 *
 * An entry with no usable label yields an empty list, so callers fall back to
 * `value` exactly as before the gateway sent labels: an older gateway, a field
 * type with no options, or a label that is blank. A gated entry carries
 * neither `value` nor `value_label`, and one that somehow does is never read,
 * so a withheld field cannot leak through its label.
 *
 * @param array $entry A `custom_fields` entry: { key, label, type, value, value_label? }.
 * @return string[] The non-blank labels in order, or array() when there is none to show.
 */
function agend_apps_records_custom_field_value_labels( array $entry ): array {
	if ( ! empty( $entry['gated'] ) || ! isset( $entry['value_label'] ) ) {
		return array();
	}

	$labels = $entry['value_label'];

	if ( is_string( $labels ) ) {
		$labels = trim( $labels );
		return '' !== $labels ? array( $labels ) : array();
	}

	if ( ! is_array( $labels ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_map( 'trim', array_map( 'strval', array_filter( $labels, 'is_scalar' ) ) ),
			'strlen'
		)
	);
}

/**
 * Display options for an item list from a surface's settings.
 *
 * Both the Directory catalogue's Detail View and the Agend Field surface
 * declare the same two controls, `item_list_layout` and `item_list_fields`, so
 * every caller reads them here. An unrecognised layout is the table, and the
 * fields control is a comma-separated list of item field keys.
 *
 * @param array $settings Surface settings.
 * @return array{layout: string, fields: string[]} `layout` is 'table' or 'list'; `fields`
 *         is empty when every item field should show.
 */
function agend_apps_records_item_list_options( array $settings ): array {
	$layout = isset( $settings['item_list_layout'] ) && 'list' === $settings['item_list_layout'] ? 'list' : 'table';
	$raw    = isset( $settings['item_list_fields'] ) && is_scalar( $settings['item_list_fields'] ) ? (string) $settings['item_list_fields'] : '';

	return array(
		'layout' => $layout,
		'fields' => array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) ) ),
	);
}

/**
 * The items of an `item_list` custom field entry, in stored order.
 *
 * @param array $entry A `custom_fields` entry: { key, label, type, value, item_fields? }.
 * @return array<int, array> The items that are objects; anything else in `value` is dropped.
 */
function agend_apps_records_item_list_items( array $entry ): array {
	$value = $entry['value'] ?? array();

	return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
}

/**
 * The columns an item list shows, in declared order.
 *
 * `item_fields` is the gateway's declaration of the list's fields, as
 * `{ key, label, type }` in the order the account declared them. A gateway
 * that predates it sends none, so the item keys in first-seen order stand in
 * for both order and headings. `$only` limits the columns to the named keys but
 * never reorders them, ignores a key the list does not have, and is dropped
 * altogether when it names nothing the list has.
 *
 * @param array    $entry A `custom_fields` entry.
 * @param string[] $only  Item field keys to show; empty shows all.
 * @return array<int, array{key: string, label: string, type: string}> `type` is 'text' or 'number'.
 */
function agend_apps_records_item_list_columns( array $entry, array $only = array() ): array {
	$columns = array();

	if ( isset( $entry['item_fields'] ) && is_array( $entry['item_fields'] ) ) {
		foreach ( $entry['item_fields'] as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['key'] ) || ! is_scalar( $field['key'] ) ) {
				continue;
			}
			$key = trim( (string) $field['key'] );
			if ( '' === $key || isset( $columns[ $key ] ) ) {
				continue;
			}
			$label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? trim( (string) $field['label'] ) : '';

			$columns[ $key ] = array(
				'key'   => $key,
				'label' => '' !== $label ? $label : $key,
				'type'  => isset( $field['type'] ) && 'number' === $field['type'] ? 'number' : 'text',
			);
		}
	}

	if ( empty( $columns ) ) {
		foreach ( agend_apps_records_item_list_items( $entry ) as $item ) {
			foreach ( array_keys( $item ) as $key ) {
				$key = (string) $key;
				if ( ! isset( $columns[ $key ] ) ) {
					$columns[ $key ] = array( 'key' => $key, 'label' => $key, 'type' => 'text' );
				}
			}
		}
	}

	$columns = array_values( $columns );

	if ( ! empty( $only ) ) {
		$chosen = array_values(
			array_filter(
				$columns,
				static function ( array $column ) use ( $only ): bool {
					return in_array( $column['key'], $only, true );
				}
			)
		);
		if ( ! empty( $chosen ) ) {
			return $chosen;
		}
	}

	return $columns;
}

/**
 * The text of one item cell, unescaped; '' when there is nothing to show.
 *
 * A `number` cell takes thousands separators and keeps the decimals the value
 * has (12500 reads 12,500 and 3.75 reads 3.75). A value that is not numeric, or
 * is too large to be a number (1e999), stays as it was written.
 *
 * @param mixed  $value The item's value for the column.
 * @param string $type  The column type, 'text' or 'number'.
 * @return string
 */
function agend_apps_records_item_list_cell_text( $value, string $type ): string {
	if ( ! is_scalar( $value ) || is_bool( $value ) ) {
		return '';
	}

	$text = trim( (string) $value );
	if ( '' === $text || 'number' !== $type || ! is_numeric( $text ) || ! is_finite( (float) $text ) ) {
		return $text;
	}

	$number = (float) $text;
	$plain  = preg_match( '/^[+-]?\d*\.?\d*$/', $text ) ? $text : number_format( $number, 10, '.', '' );
	$dot    = strpos( $plain, '.' );

	return number_format_i18n( $number, false === $dot ? 0 : strlen( rtrim( substr( $plain, $dot + 1 ), '0' ) ) );
}

/**
 * Renders an `item_list` custom field as a table or a list.
 *
 * The one PHP renderer for an item list, shared by the built-in detail and the
 * Agend Field surface; assets/js/directory-catalogue.js draws the same markup
 * client-side. Table (the default) is a header row of the item field labels and
 * one row per item in stored order, in a container that scrolls sideways so a
 * wide table never widens the page. List is one entry per item, the shown
 * values joined by ", " in declared order. An item with nothing to show in the
 * chosen fields is left out of both, and a list with no item left renders
 * nothing. Every cell is escaped.
 *
 * @param array $entry   A `custom_fields` entry: { key, label, type, value, item_fields? }.
 * @param array $options `layout` ('table' or 'list') and `fields` (item field
 *                       keys to show), as agend_apps_records_item_list_options() returns.
 * @return string Escaped HTML, or '' when there is nothing to show.
 */
function agend_apps_records_render_item_list( array $entry, array $options = array() ): string {
	if ( ! empty( $entry['gated'] ) ) {
		return '';
	}

	$columns = agend_apps_records_item_list_columns( $entry, isset( $options['fields'] ) && is_array( $options['fields'] ) ? $options['fields'] : array() );
	if ( empty( $columns ) ) {
		return '';
	}

	$rows = array();
	foreach ( agend_apps_records_item_list_items( $entry ) as $item ) {
		$cells = array();
		foreach ( $columns as $column ) {
			$cells[] = agend_apps_records_item_list_cell_text( $item[ $column['key'] ] ?? '', $column['type'] );
		}
		if ( '' !== implode( '', $cells ) ) {
			$rows[] = $cells;
		}
	}
	if ( empty( $rows ) ) {
		return '';
	}

	if ( 'list' === ( $options['layout'] ?? 'table' ) ) {
		$entries = '';
		foreach ( $rows as $cells ) {
			$entries .= '<li>' . esc_html( implode( ', ', array_filter( $cells, 'strlen' ) ) ) . '</li>';
		}
		return '<ul class="agend-dir-cf__item-list agend-dir-cf__item-list--list">' . $entries . '</ul>';
	}

	$head = '';
	foreach ( $columns as $column ) {
		$head .= '<th' . ( 'number' === $column['type'] ? ' class="agend-dir-cf__item-list-cell--number"' : '' ) . ' scope="col">' . esc_html( $column['label'] ) . '</th>';
	}

	$body = '';
	foreach ( $rows as $cells ) {
		$body .= '<tr>';
		foreach ( $cells as $index => $text ) {
			$body .= '<td' . ( 'number' === $columns[ $index ]['type'] ? ' class="agend-dir-cf__item-list-cell--number"' : '' ) . '>' . esc_html( $text ) . '</td>';
		}
		$body .= '</tr>';
	}

	// The wrapper scrolls, so it takes keyboard focus for a browser that does not
	// focus a scroller itself, and is named for a screen reader by the field.
	$name = isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? trim( (string) $entry['label'] ) : '';
	$wrap = '<div class="agend-dir-cf__item-list-wrap" tabindex="0"' . ( '' !== $name ? ' role="region" aria-label="' . esc_attr( $name ) . '"' : '' ) . '>';

	return $wrap . '<table class="agend-dir-cf__item-list agend-dir-cf__item-list--table"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
}

/**
 * The short label shown in place of a gated custom field's value.
 *
 * Mirrors the gateway's `state` vocabulary for a withheld field. The plain
 * words are deliberately non-specific: the gateway never discloses which tier,
 * segment or entitlement would unlock the field, and neither does this.
 *
 * @param string $state The gateway's gate state for the field.
 * @return string The label.
 */
function agend_apps_records_gated_field_label( string $state ): string {
	switch ( $state ) {
		case 'authentication_required':
			$label = __( 'Sign in to view', 'agend-apps-core' );
			break;
		case 'membership_required':
			$label = __( 'Members only', 'agend-apps-core' );
			break;
		case 'plan_required':
			$label = __( 'Not included in your subscription', 'agend-apps-core' );
			break;
		default:
			$label = __( 'Restricted', 'agend-apps-core' );
	}

	/**
	 * Filters the label shown for a gated custom field.
	 *
	 * @param string $label The label.
	 * @param string $state The gateway's gate state.
	 */
	return (string) apply_filters( 'agend_apps_records_gated_field_label', $label, $state );
}
