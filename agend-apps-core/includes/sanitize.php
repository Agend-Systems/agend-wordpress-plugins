<?php
/**
 * Response HTML sanitisation.
 *
 * Defence-in-depth: rich-text fields (course/event descriptions) that a widget
 * renders as HTML are scrubbed with WordPress core's `wp_kses_post()` as they
 * pass back through the plugin, regardless of what the gateway returned. This
 * is intentionally NOT reliant on upstream sanitisation — it is an independent
 * layer between the gateway and the browser. The widget applies a further
 * DOMPurify pass at the DOM sink.
 *
 * Hooked onto the decoded-response filters the API wrappers already expose, so
 * every caller of those wrappers (the REST proxy and any sibling plugin) gets
 * sanitised output, not just one route.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs `wp_kses_post()` over the named string fields of a single decoded item.
 *
 * @param mixed $item   A decoded response item (associative array) or any other value.
 * @param array $fields HTML-bearing field names to sanitise.
 * @return mixed The item with its HTML fields sanitised, or the value unchanged.
 */
function agend_apps_kses_item( $item, array $fields ) {
	if ( ! is_array( $item ) ) {
		return $item;
	}

	foreach ( $fields as $field ) {
		if ( isset( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
			$item[ $field ] = wp_kses_post( $item[ $field ] );
		}
	}

	return $item;
}

/**
 * Sanitises HTML-bearing fields on a decoded gateway response envelope.
 *
 * Handles both a single resource (`data` is an object) and a list (`data` is a
 * list of objects). Anything else is returned untouched.
 *
 * @param mixed $response Decoded response (canonical `{ success, data }` envelope).
 * @param array $fields   HTML-bearing field names to sanitise.
 * @return mixed The response with its HTML fields sanitised.
 */
function agend_apps_sanitize_html_fields( $response, array $fields ) {
	if ( ! is_array( $response ) || ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
		return $response;
	}

	$data = $response['data'];

	// A list: sequential integer keys with array items. Sanitise each item.
	// (array_is_list() is PHP 8.1+; this plugin targets 7.4, so detect by hand.)
	$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 ) && count( $data ) > 0;

	if ( $is_list ) {
		$response['data'] = array_map(
			static function ( $item ) use ( $fields ) {
				return agend_apps_kses_item( $item, $fields );
			},
			$data
		);
	} else {
		$response['data'] = agend_apps_kses_item( $data, $fields );
	}

	return $response;
}

/**
 * Registers the sanitisation callbacks on the API response filters.
 *
 * `description` is the only field these surfaces render as HTML; other fields
 * (title, category, instructor, learning outcomes) are rendered as plain text
 * by the widgets and are left untouched here.
 */
function agend_apps_register_html_sanitizers(): void {
	$html_fields = array( 'description' );

	$filters = array(
		'agend_apps_lms_get_courses_response',
		'agend_apps_lms_get_course_response',
		'agend_apps_events_get_events_response',
		'agend_apps_events_get_event_response',
	);

	foreach ( $filters as $filter ) {
		add_filter(
			$filter,
			static function ( $response ) use ( $html_fields ) {
				return agend_apps_sanitize_html_fields( $response, $html_fields );
			},
			10,
			1
		);
	}
}
agend_apps_register_html_sanitizers();
