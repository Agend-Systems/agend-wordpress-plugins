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
