<?php
/**
 * Deprecated `agend_elementor_*` names for the record layer.
 *
 * The record layer shipped under the Elementor plugin's prefix
 * (`agend_elementor_*`, `Agend_Elementor_*`); a site's own code, a theme, or a
 * sibling plugin may still call those names directly. This file makes every
 * such name resolve for one release, with a deprecation notice, before it is
 * removed.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Wrapped: every function a file outside includes/records/ called before the
// rename, plus the asset and label helpers a sibling plugin could have called.

function agend_elementor_courses_list_args( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_courses_list_args' );
	return agend_apps_records_courses_list_args( ...$args );
}

function agend_elementor_detail_template_labels( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_detail_template_labels' );
	return agend_apps_records_detail_template_labels( ...$args );
}

function agend_elementor_events_list_args( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_events_list_args' );
	return agend_apps_records_events_list_args( ...$args );
}

function agend_elementor_fetch_list( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_fetch_list' );
	return agend_apps_records_fetch_list( ...$args );
}

function agend_elementor_field_applies( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_field_applies' );
	return agend_apps_records_field_applies( ...$args );
}

function agend_elementor_field_kind( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_field_kind' );
	return agend_apps_records_field_kind( ...$args );
}

function agend_elementor_field_options( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_field_options' );
	return agend_apps_records_field_options( ...$args );
}

function agend_elementor_field_terms( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_field_terms' );
	return agend_apps_records_field_terms( ...$args );
}

function agend_elementor_field_value( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_field_value' );
	return agend_apps_records_field_value( ...$args );
}

function agend_elementor_filter_config( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_filter_config' );
	return agend_apps_records_filter_config( ...$args );
}

function agend_elementor_filter_options( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_filter_options' );
	return agend_apps_records_filter_options( ...$args );
}

function agend_elementor_filter_registry( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_filter_registry' );
	return agend_apps_records_filter_registry( ...$args );
}

function agend_elementor_format_field( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_format_field' );
	return agend_apps_records_format_field( ...$args );
}

function agend_elementor_listings_list_args( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_listings_list_args' );
	return agend_apps_records_listings_list_args( ...$args );
}

function agend_elementor_pill_field_options( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_pill_field_options' );
	return agend_apps_records_pill_field_options( ...$args );
}

function agend_elementor_preview_record( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_preview_record' );
	return agend_apps_records_preview_record( ...$args );
}

function agend_elementor_render_cards( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_render_cards' );
	return agend_apps_records_render_cards( ...$args );
}

function agend_elementor_render_field( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_render_field' );
	return agend_apps_records_render_field( ...$args );
}

function agend_elementor_shop_cart_enabled( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_shop_cart_enabled' );
	return agend_apps_records_shop_cart_enabled( ...$args );
}

function agend_elementor_shop_cart_page_url( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_shop_cart_page_url' );
	return agend_apps_records_shop_cart_page_url( ...$args );
}

function agend_elementor_show_achievements_enabled( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_show_achievements_enabled' );
	return agend_apps_records_show_achievements_enabled( ...$args );
}

function agend_elementor_ssr_colour_style( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_ssr_colour_style' );
	return agend_apps_records_ssr_colour_style( ...$args );
}

function agend_elementor_ssr_detail_enabled( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_ssr_detail_enabled' );
	return agend_apps_records_ssr_detail_enabled( ...$args );
}

function agend_elementor_unwrap_list( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_unwrap_list' );
	return agend_apps_records_unwrap_list( ...$args );
}

function agend_elementor_asset_url( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_asset_url' );
	return agend_apps_records_asset_url( ...$args );
}

function agend_elementor_asset_version( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_asset_version' );
	return agend_apps_records_asset_version( ...$args );
}

function agend_elementor_register_dompurify( ...$args ) {
	_deprecated_function( __FUNCTION__, '1.8.0', 'agend_apps_records_register_dompurify' );
	return agend_apps_records_register_dompurify( ...$args );
}

if ( ! class_exists( 'Agend_Elementor_Pages', false ) ) {
	class_alias( 'Agend_Apps_Records_Pages', 'Agend_Elementor_Pages' );
}
if ( ! class_exists( 'Agend_Elementor_Fragments_Controller', false ) ) {
	class_alias( 'Agend_Apps_Records_Fragments_Controller', 'Agend_Elementor_Fragments_Controller' );
}
if ( ! class_exists( 'Agend_Elementor_Record_Context', false ) ) {
	class_alias( 'Agend_Apps_Records_Record_Context', 'Agend_Elementor_Record_Context' );
}
if ( ! class_exists( 'Agend_Elementor_Filter_Context', false ) ) {
	class_alias( 'Agend_Apps_Records_Filter_Context', 'Agend_Elementor_Filter_Context' );
}

// Not AGEND_ELEMENTOR_REWRITE_VERSION: an Elementor plugin predating the move
// still defines that one itself.

if ( ! defined( 'AGEND_ELEMENTOR_SSR_DETAIL_OPTION' ) ) {
	define( 'AGEND_ELEMENTOR_SSR_DETAIL_OPTION', AGEND_APPS_RECORDS_SSR_DETAIL_OPTION );
}
if ( ! defined( 'AGEND_ELEMENTOR_SHOW_ACHIEVEMENTS_OPTION' ) ) {
	define( 'AGEND_ELEMENTOR_SHOW_ACHIEVEMENTS_OPTION', AGEND_APPS_RECORDS_SHOW_ACHIEVEMENTS_OPTION );
}
if ( ! defined( 'AGEND_ELEMENTOR_EVENTS_PAGE_OPTION' ) ) {
	define( 'AGEND_ELEMENTOR_EVENTS_PAGE_OPTION', AGEND_APPS_RECORDS_EVENTS_PAGE_OPTION );
}
if ( ! defined( 'AGEND_ELEMENTOR_COURSES_PAGE_OPTION' ) ) {
	define( 'AGEND_ELEMENTOR_COURSES_PAGE_OPTION', AGEND_APPS_RECORDS_COURSES_PAGE_OPTION );
}
if ( ! defined( 'AGEND_ELEMENTOR_DIRECTORY_PAGE_OPTION' ) ) {
	define( 'AGEND_ELEMENTOR_DIRECTORY_PAGE_OPTION', AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION );
}
if ( ! defined( 'AGEND_ELEMENTOR_EVENT_DETAIL_TEMPLATE_OPTION' ) ) {
	define( 'AGEND_ELEMENTOR_EVENT_DETAIL_TEMPLATE_OPTION', AGEND_APPS_RECORDS_EVENT_DETAIL_TEMPLATE_OPTION );
}
if ( ! defined( 'AGEND_ELEMENTOR_COURSE_DETAIL_TEMPLATE_OPTION' ) ) {
	define( 'AGEND_ELEMENTOR_COURSE_DETAIL_TEMPLATE_OPTION', AGEND_APPS_RECORDS_COURSE_DETAIL_TEMPLATE_OPTION );
}
if ( ! defined( 'AGEND_ELEMENTOR_LISTING_DETAIL_TEMPLATE_OPTION' ) ) {
	define( 'AGEND_ELEMENTOR_LISTING_DETAIL_TEMPLATE_OPTION', AGEND_APPS_RECORDS_LISTING_DETAIL_TEMPLATE_OPTION );
}
if ( ! defined( 'AGEND_ELEMENTOR_FIELD_KINDS' ) ) {
	define( 'AGEND_ELEMENTOR_FIELD_KINDS', AGEND_APPS_RECORDS_FIELD_KINDS );
}
if ( ! defined( 'AGEND_ELEMENTOR_FRAGMENT_MAX_LIMIT' ) ) {
	define( 'AGEND_ELEMENTOR_FRAGMENT_MAX_LIMIT', AGEND_APPS_RECORDS_FRAGMENT_MAX_LIMIT );
}

// The six public filters (agend_elementor_cart_mode, agend_elementor_dedicated_page_id,
// agend_elementor_detail_url, agend_elementor_filter_registry,
// agend_elementor_show_achievements_enabled, agend_elementor_ssr_detail_enabled)
// need no shim here: each call site in records/ runs
// apply_filters_deprecated() against the old hook name directly, so an
// existing add_filter() on the old name still applies.
