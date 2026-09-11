<?php
/**
 * Server render of the Agend Panel (record block) surface.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * A panel is one of the composite fragments in fragments.php -- tickets,
 * sponsors, facts, outcomes, reviews and so on -- picked by the surface's
 * `block` setting and rendered against whichever record
 * agend_apps_records_resolve_record_context() resolves for the request: a
 * record a template renderer pushed, live, or an editor preview record.
 *
 * Some panel keys are retired: they used to be the only way to show a single
 * value (a description, categories, opening hours, one custom field), and now
 * have a Field/Pills equivalent instead, but a template built on one of them
 * keeps rendering exactly as before {@see agend_apps_records_retired_block_field()}.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Panel key => [ label, record type, fragment function ].
 *
 * The record type and fragment function are render-time concerns kept here
 * rather than in the schema, which only lists the pickable keys (see
 * agend_apps_records_block_options()). This map is deliberately WIDER than
 * the picker: it still carries the single-value keys that moved to Agend
 * Field and Agend Pills, so a template saved against one of them keeps
 * rendering exactly as before (see agend_apps_records_retired_block_field()).
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function agend_apps_records_record_block_blocks(): array {
	return array(
		'event_facts'           => array( __( 'Event facts (date, location, format)', 'agend-apps-core' ), 'event', 'agend_apps_records_fragment_event_facts' ),
		'event_registration'    => array( __( 'Event registration panel', 'agend-apps-core' ), 'event', 'agend_apps_records_fragment_event_registration' ),
		'event_tickets'         => array( __( 'Event tickets and pricing', 'agend-apps-core' ), 'event', 'agend_apps_records_fragment_event_tickets' ),
		'event_sponsors'        => array( __( 'Event sponsors', 'agend-apps-core' ), 'event', 'agend_apps_records_fragment_event_sponsors' ),
		'course_meta'           => array( __( 'Course details (level, format, duration)', 'agend-apps-core' ), 'course', 'agend_apps_records_fragment_course_meta' ),
		'course_outcomes'       => array( __( 'Course learning outcomes', 'agend-apps-core' ), 'course', 'agend_apps_records_fragment_course_outcomes' ),
		'course_enrolment'      => array( __( 'Course pricing and enrolment', 'agend-apps-core' ), 'course', 'agend_apps_records_fragment_course_enrolment' ),
		'listing_about'         => array( __( 'Listing about', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_about' ),
		'listing_contact'       => array( __( 'Listing contact and links', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_contact' ),
		'listing_categories'    => array( __( 'Listing categories', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_categories' ),
		'listing_tags'          => array( __( 'Listing tags', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_tags' ),
		'listing_gallery'       => array( __( 'Listing gallery', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_gallery' ),
		'listing_locations'     => array( __( 'Listing locations', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_locations' ),
		'listing_hours'         => array( __( 'Listing business hours', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_hours' ),
		'listing_custom_fields' => array( __( 'Listing custom fields', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_custom_fields' ),
		'listing_achievements'  => array( __( 'Listing badges and credentials', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_achievements' ),
		'listing_reviews'       => array( __( 'Listing reviews', 'agend-apps-core' ), 'listing', 'agend_apps_records_fragment_listing_reviews' ),
	);
}

/**
 * Ticket types for an event, from the render context or fetched once per
 * slug per request.
 *
 * The per-slug memo is a function-static, so it persists for the life of the
 * request regardless of how many times this is called (by the reason
 * resolver and then again by the renderer, for instance): a second call for
 * the same slug reads the memo rather than reaching the gateway again.
 *
 * @param string $slug  Event slug.
 * @param array  $extra Render context; `tickets` carries a preloaded list.
 * @return array
 */
function agend_apps_records_record_block_tickets_for( string $slug, array $extra ): array {
	if ( isset( $extra['tickets'] ) && is_array( $extra['tickets'] ) ) {
		return $extra['tickets'];
	}
	static $memo = array();
	if ( '' === $slug || ! function_exists( 'agend_apps_events_get_tickets' ) ) {
		return array();
	}
	if ( ! isset( $memo[ $slug ] ) ) {
		$response      = agend_apps_events_get_tickets( $slug );
		$memo[ $slug ] = ( ! is_wp_error( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) )
			? $response['data']
			: array();
	}
	return $memo[ $slug ];
}

/**
 * Resolves the whole "does this panel render, and if not why" decision in one
 * pass: which record is in scope, which panel key was picked, whether it
 * applies to that record, and -- when it does -- the fragment HTML itself.
 *
 * agend_apps_records_record_block_render_reason() and
 * agend_apps_records_render_record_block() both read this single source of
 * truth instead of restating its conditions, following the shape of
 * agend_apps_records_filter_resolve() (render/filter.php). Building the
 * fragment here, once, rather than in the renderer, is also what stops the
 * tickets panel from being computed twice: agend_apps_records_record_block_tickets_for()
 * is only ever called from this one place.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-block' )).
 * @param array $opts     'preview' (bool) and 'preview_type' (string), passed
 *                        straight to agend_apps_records_resolve_record_context().
 * @return array{reason: string, html: string, type: string, key: string} `type`
 *               is the record type in scope (used for the wrapper's colour and
 *               root class); `html` is the fragment output, non-'' if and only
 *               if `reason` is '' or 'retired'.
 */
function agend_apps_records_record_block_resolve( array $settings, array $opts = array() ): array {
	$ctx = agend_apps_records_resolve_record_context( $opts );
	$key = (string) ( $settings['block'] ?? '' );

	if ( '' === $ctx['type'] ) {
		return array( 'reason' => '', 'html' => '', 'type' => '', 'key' => $key );
	}

	$blocks = agend_apps_records_record_block_blocks();
	if ( ! isset( $blocks[ $key ] ) ) {
		return array( 'reason' => '', 'html' => '', 'type' => $ctx['type'], 'key' => $key );
	}

	list( , $block_type, $fragment ) = $blocks[ $key ];
	if ( $block_type !== $ctx['type'] ) {
		return array( 'reason' => 'wrong_type', 'html' => '', 'type' => $ctx['type'], 'key' => $key );
	}

	if ( ! function_exists( $fragment ) ) {
		return array( 'reason' => '', 'html' => '', 'type' => $ctx['type'], 'key' => $key );
	}

	if ( 'event_tickets' === $key && $ctx['is_preview'] ) {
		return array( 'reason' => 'tickets_live_only', 'html' => '', 'type' => $ctx['type'], 'key' => $key );
	}

	$record = $ctx['record'];
	$slug   = isset( $record['slug'] ) ? (string) $record['slug'] : (string) ( $ctx['extra']['slug'] ?? '' );
	$extra  = $ctx['extra'];
	if ( ! isset( $extra['host'] ) && ! empty( $extra['host_page_id'] ) ) {
		$extra['host'] = get_post( (int) $extra['host_page_id'] );
	}

	switch ( $key ) {
		case 'event_tickets':
			$html = $fragment( $record, agend_apps_records_record_block_tickets_for( $slug, $extra ) );
			break;
		case 'event_registration':
		case 'course_enrolment':
		case 'listing_reviews':
			$html = $fragment( $record, $slug, $extra );
			break;
		default:
			$html = $fragment( $record );
	}

	if ( '' === trim( $html ) ) {
		return array( 'reason' => 'empty_fragment', 'html' => '', 'type' => $ctx['type'], 'key' => $key );
	}

	$retired = function_exists( 'agend_apps_records_retired_block_field' ) ? agend_apps_records_retired_block_field( $key ) : '';

	return array(
		'reason' => '' !== $retired ? 'retired' : '',
		'html'   => $html,
		'type'   => $ctx['type'],
		'key'    => $key,
	);
}

/**
 * Why a panel with these settings renders nothing, or '' when it renders (see
 * agend_apps_records_render_record_block()).
 *
 * 'retired' is the one code that does not mean "renders nothing": a retired
 * key {@see agend_apps_records_retired_block_field()} still renders its
 * fragment exactly as before, so a template built on one is not left with a
 * blank panel. It is still worth a stable code, so a caller other than the
 * Elementor widget can also tell that this particular key is one an author
 * should be nudged towards Agend Field or Agend Pills instead.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-block' )).
 * @param array $opts     'preview' (bool) and 'preview_type' (string), passed
 *                        straight to agend_apps_records_resolve_record_context().
 * @return string '', or one of 'wrong_type', 'tickets_live_only',
 *                'empty_fragment', 'retired'.
 */
function agend_apps_records_record_block_render_reason( array $settings, array $opts = array() ): string {
	return agend_apps_records_record_block_resolve( $settings, $opts )['reason'];
}

/**
 * Renders the Agend Panel surface: the fragment for the settings' `block`
 * key, wrapped in the catalogue root class and colour variables its CSS
 * expects, so a panel placed in a detail template lands on the same classes
 * as the built-in detail render.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-block' )).
 * @param array $opts     'preview' (bool) and 'preview_type' (string), passed
 *                        straight to agend_apps_records_resolve_record_context().
 * @return string The rendered markup, or '' when the panel renders nothing
 *                (see agend_apps_records_record_block_render_reason() for why,
 *                when a caller needs to know).
 */
function agend_apps_records_render_record_block( array $settings, array $opts = array() ): string {
	$resolved = agend_apps_records_record_block_resolve( $settings, $opts );
	if ( '' === $resolved['html'] ) {
		return '';
	}

	$roots  = array( 'course' => 'agend-courses-catalogue', 'listing' => 'agend-directory-catalogue', 'event' => 'agend-events-catalogue' );
	$prefix = array( 'course' => 'agend-lms', 'listing' => 'agend-dir', 'event' => 'agend-ev' );
	$root   = $roots[ $resolved['type'] ] ?? 'agend-events-catalogue';
	$style  = agend_apps_records_ssr_colour_style( $prefix[ $resolved['type'] ] ?? 'agend-ev' );

	return '<div class="agend-record-block agend-record-block--' . esc_attr( $resolved['key'] ) . ' ' . esc_attr( $root ) . ' ' . esc_attr( $root ) . '--fragment" style="' . esc_attr( $style ) . '">' . $resolved['html'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fragments escape internally.
}
