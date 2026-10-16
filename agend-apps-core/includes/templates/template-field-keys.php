<?php
/**
 * Which record fields a template's elements are bound to, independent of the
 * page builder the template was authored in.
 *
 * Each adapter walks its own saved format (Elementor's `_elementor_data`, the
 * block editor's parsed blocks) and hands every Agend element's kind and saved
 * settings to agend_apps_records_template_binding_keys(), so the meaning of a
 * binding is decided once.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The elements that bind a record field through a `field` setting, mapped to
 * the schema whose default applies when the setting was never changed: both
 * Elementor and the block editor save only the settings an author changed.
 */
const AGEND_APPS_RECORDS_FIELD_BINDING_SCHEMAS = array(
	'record-field' => 'agend_apps_records_schema_record_field',
	'record-pills' => 'agend_apps_records_schema_record_pills',
	'record-image' => 'agend_apps_records_schema_record_image',
);

/**
 * The search include token a bound listing field needs. A field missing here
 * is carried on every search card already.
 */
const AGEND_APPS_RECORDS_SEARCH_INCLUDE_BY_FIELD = array(
	'listing:categories' => 'categories',
	'listing:phone'      => 'phone',
);

/**
 * An empty key set.
 *
 * @return array{fields: string[], custom_fields: string[]}
 */
function agend_apps_records_empty_binding_keys(): array {
	return array(
		'fields'        => array(),
		'custom_fields' => array(),
	);
}

/**
 * The default an element's schema declares for one of its settings, or ''.
 *
 * @param string $element Element kind, e.g. 'record-field'.
 * @param string $setting The setting's name.
 * @return string
 */
function agend_apps_records_binding_default_field( string $element, string $setting = 'field' ): string {
	$schema_function = AGEND_APPS_RECORDS_FIELD_BINDING_SCHEMAS[ $element ] ?? '';
	if ( '' === $schema_function || ! function_exists( $schema_function ) ) {
		return '';
	}

	foreach ( $schema_function()['sections'] ?? array() as $section ) {
		foreach ( $section['fields'] ?? array() as $field ) {
			if ( $setting === ( $field['name'] ?? '' ) ) {
				return (string) ( $field['default'] ?? '' );
			}
		}
	}

	return '';
}

/**
 * The keys one element binds.
 *
 * @param string $element  Element kind, e.g. 'record-field'. Anything that is
 *                         not a field-binding element binds nothing.
 * @param array  $settings The element's saved settings.
 * @return array{fields: string[], custom_fields: string[]}
 */
function agend_apps_records_template_binding_keys( string $element, array $settings ): array {
	$keys = agend_apps_records_empty_binding_keys();
	if ( ! array_key_exists( $element, AGEND_APPS_RECORDS_FIELD_BINDING_SCHEMAS ) ) {
		return $keys;
	}

	$field = is_scalar( $settings['field'] ?? null ) ? trim( (string) $settings['field'] ) : '';
	if ( '' === $field ) {
		$field = agend_apps_records_binding_default_field( $element );
	}
	if ( '' === $field ) {
		return $keys;
	}
	$keys['fields'][] = $field;

	// Agend Image chooses its picture by a term field when "Choose the image by
	// term" is on, and that field (for example All categories) needs its data.
	// A block stores the toggle as a boolean, an Elementor widget as 'yes'.
	if ( 'record-image' === $element && in_array( $settings['use_term_images'] ?? '', array( true, 'yes' ), true ) ) {
		$term_field = is_scalar( $settings['term_field'] ?? null ) ? trim( (string) $settings['term_field'] ) : '';
		$term_field = '' !== $term_field ? $term_field : agend_apps_records_binding_default_field( $element, 'term_field' );
		if ( '' !== $term_field ) {
			$keys['fields'][] = $term_field;
		}
	}

	if ( in_array( $field, array( 'common:custom_field', 'common:custom_field_label' ), true ) ) {
		// The same precedence as agend_apps_records_record_field_custom_field_key():
		// the picked key wins over the typed one.
		$choice = trim( (string) ( $settings['custom_field_key_choice'] ?? '' ) );
		$custom = '' !== $choice ? $choice : trim( (string) ( $settings['custom_field_key'] ?? '' ) );
		if ( '' !== $custom ) {
			$keys['custom_fields'][] = $custom;
		}
	}

	return $keys;
}

/**
 * Several key sets as one, each list distinct and sorted.
 *
 * @param array ...$sets Key sets as agend_apps_records_template_binding_keys() returns them.
 * @return array{fields: string[], custom_fields: string[]}
 */
function agend_apps_records_merge_binding_keys( array ...$sets ): array {
	$merged = agend_apps_records_empty_binding_keys();
	foreach ( $sets as $set ) {
		foreach ( array( 'fields', 'custom_fields' ) as $list ) {
			$merged[ $list ] = array_merge( $merged[ $list ], (array) ( $set[ $list ] ?? array() ) );
		}
	}
	foreach ( array( 'fields', 'custom_fields' ) as $list ) {
		$merged[ $list ] = array_values( array_unique( array_map( 'strval', $merged[ $list ] ) ) );
		sort( $merged[ $list ] );
	}
	return $merged;
}

/**
 * The directory search `include` a template's bound fields need, as the
 * comma list the gateway reads, or '' when it needs none.
 *
 * @param array $keys The template's keys, as Agend_Apps_Templates::field_keys() returns them.
 * @return string
 */
function agend_apps_records_search_include_for_keys( array $keys ): string {
	$tokens = array();
	foreach ( (array) ( $keys['fields'] ?? array() ) as $field ) {
		$token = AGEND_APPS_RECORDS_SEARCH_INCLUDE_BY_FIELD[ (string) $field ] ?? '';
		if ( '' !== $token ) {
			$tokens[ $token ] = $token;
		}
	}
	ksort( $tokens );
	return implode( ',', $tokens );
}
