<?php
/**
 * Server render of the record link surface (formerly the Elementor "Agend
 * Link / Button" widget): the actions a card or detail page offers for the
 * current record.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema( 'record-link' )`) and echoes the
 * returned HTML.
 *
 * The `register` action emits the same `data-agend-event-slug` button the
 * built-in detail uses, so the existing registration flow in
 * assets/js/events-catalogue.js hydrates it without changes.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The record's detail URL from context, falling back to the page resolver.
 *
 * @param array $ctx Resolved record context (see agend_apps_records_resolve_record_context()).
 * @return string
 */
function agend_apps_records_record_link_detail_url( array $ctx ): string {
	return (string) ( agend_apps_records_field_value( 'common:detail_url', $ctx['type'], $ctx['record'], $ctx['extra'] ) ?? '' );
}

/**
 * The catalogue (dedicated or host) page URL.
 *
 * @param array $ctx Resolved record context.
 * @return string
 */
function agend_apps_records_record_link_catalogue_url( array $ctx ): string {
	$url = Agend_Apps_Records_Pages::page_url( $ctx['type'] );
	if ( '' === $url && ! empty( $ctx['extra']['host_page_id'] ) ) {
		$permalink = get_permalink( (int) $ctx['extra']['host_page_id'] );
		$url       = is_string( $permalink ) ? $permalink : '';
	}
	return $url;
}

/**
 * Class list for the rendered element.
 *
 * @param array  $settings Surface settings.
 * @param string $extra    Additional modifier, e.g. 'action-detail'.
 * @return string
 */
function agend_apps_records_record_link_classes( array $settings, string $extra = '' ): string {
	$classes   = array( 'agend-record-link' );
	$classes[] = 'link' === ( $settings['style_as'] ?? 'button' ) ? 'agend-record-link--text' : 'agend-record-link--button';
	if ( 'yes' === ( $settings['full_width'] ?? '' ) ) {
		$classes[] = 'agend-record-link--full';
	}
	if ( '' !== $extra ) {
		$classes[] = 'agend-record-link--' . $extra;
	}
	return implode( ' ', $classes );
}

/**
 * Builds an anchor with the shared classes, or, inside a card that is itself
 * one big link, a `<span>` carrying the same classes.
 *
 * Inside a card that is one big link a nested anchor is invalid HTML; the
 * label renders as a `<span>` instead and the card's own link does the
 * navigating.
 *
 * @param string $href     Destination.
 * @param string $label    Visible label.
 * @param array  $settings Surface settings.
 * @param array  $ctx      Resolved record context.
 * @param string $action   The configured action, for the element's modifier class.
 * @param bool   $new_tab  Whether to open in a new tab.
 * @return string
 */
function agend_apps_records_record_link_output_anchor( string $href, string $label, array $settings, array $ctx, string $action, bool $new_tab = false ): string {
	$classes = agend_apps_records_record_link_classes( $settings, 'action-' . $action );

	if ( ! empty( $ctx['extra']['in_card_link'] ) ) {
		return '<span class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</span>';
	}

	$attrs = ' class="' . esc_attr( $classes ) . '" href="' . esc_url( $href ) . '"';
	if ( $new_tab ) {
		$attrs .= ' target="_blank" rel="noopener"';
	}

	return '<a' . $attrs . '>' . esc_html( $label ) . '</a>';
}

/**
 * A "renders nothing" recipe: no reason to give beyond that, unless `$reason`
 * says otherwise.
 *
 * @param array  $ctx    Resolved record context.
 * @param string $reason '' or one of the codes agend_apps_records_record_link_resolve() documents.
 * @return array
 */
function agend_apps_records_record_link_none( array $ctx, string $reason = '' ): array {
	return array(
		'reason'   => $reason,
		'ctx'      => $ctx,
		'kind'     => 'none',
		'action'   => '',
		'href'     => '',
		'label'    => '',
		'new_tab'  => false,
		'disabled' => false,
		'slug'     => '',
	);
}

/**
 * Resolves the whole "does this link render, and if not why, and what should
 * it say" decision in one pass, per the configured action.
 *
 * Three actions carry a condition an author can be told about when it fails
 * (`ical` and `register` need an event with a slug in scope; `enrol` needs a
 * course), stated exactly once here rather than in both
 * agend_apps_records_record_link_render_reason() and
 * agend_apps_records_render_record_link(), on
 * agend_apps_records_filter_resolve()'s shape. Every other silent
 * "renders nothing" case (no record context at all; `detail`/`catalogue` with
 * no URL to link to; a blank `custom` URL template; an already
 * enrolled/registered record hidden by its own toggle) carries no reason
 * code, matching the widget's own render() before this moved: none of those
 * has ever shown an editor notice, so there is nothing more specific to say
 * than "renders nothing" -- the same distinction
 * agend_apps_records_filter_render_reason() draws for a filter setting with no
 * colon.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-link' )).
 * @param array $opts     'preview' / 'preview_type', passed straight to
 *                        agend_apps_records_resolve_record_context().
 * @return array{reason: string, ctx: array, kind: string, action: string, href: string, label: string, new_tab: bool, disabled: bool, slug: string}
 *              `kind` is 'none' (render nothing), 'anchor', or 'button' (the
 *              `register` action). `reason` is '', 'ical_wrong_type',
 *              'enrol_wrong_type', or 'register_wrong_type'.
 */
function agend_apps_records_record_link_resolve( array $settings, array $opts = array() ): array {
	$ctx = agend_apps_records_resolve_record_context( $opts );
	if ( '' === $ctx['type'] ) {
		return agend_apps_records_record_link_none( $ctx );
	}

	$record = $ctx['record'];
	$slug   = isset( $record['slug'] ) ? (string) $record['slug'] : (string) ( $ctx['extra']['slug'] ?? '' );
	$title  = (string) ( agend_apps_records_field_value( 'common:title', $ctx['type'], $record, $ctx['extra'] ) ?? '' );
	$text   = trim( (string) ( $settings['text'] ?? '' ) );
	$action = (string) ( $settings['action'] ?? 'detail' );

	switch ( $action ) {
		case 'detail':
			$href = agend_apps_records_record_link_detail_url( $ctx );
			if ( '' === $href ) {
				return agend_apps_records_record_link_none( $ctx );
			}
			return array(
				'reason'   => '',
				'ctx'      => $ctx,
				'kind'     => 'anchor',
				'action'   => $action,
				'href'     => $href,
				'label'    => '' !== $text ? $text : __( 'View details', 'agend-apps-core' ),
				'new_tab'  => false,
				'disabled' => false,
				'slug'     => $slug,
			);

		case 'catalogue':
			$href = agend_apps_records_record_link_catalogue_url( $ctx );
			if ( '' === $href ) {
				return agend_apps_records_record_link_none( $ctx );
			}
			$labels = array(
				'course'  => __( 'Back to Courses', 'agend-apps-core' ),
				'listing' => __( 'Back to Directory', 'agend-apps-core' ),
			);
			return array(
				'reason'   => '',
				'ctx'      => $ctx,
				'kind'     => 'anchor',
				'action'   => $action,
				'href'     => $href,
				'label'    => '' !== $text ? $text : ( $labels[ $ctx['type'] ] ?? __( 'Back to Events', 'agend-apps-core' ) ),
				'new_tab'  => false,
				'disabled' => false,
				'slug'     => $slug,
			);

		case 'ical':
			if ( 'event' !== $ctx['type'] || '' === $slug ) {
				return agend_apps_records_record_link_none( $ctx, 'ical_wrong_type' );
			}
			return array(
				'reason'   => '',
				'ctx'      => $ctx,
				'kind'     => 'anchor',
				'action'   => $action,
				'href'     => rest_url( 'agend-apps/v1/events/' . rawurlencode( $slug ) . '/ical' ),
				'label'    => '' !== $text ? $text : __( 'Add to Calendar', 'agend-apps-core' ),
				'new_tab'  => 'yes' === ( $settings['new_tab'] ?? '' ),
				'disabled' => false,
				'slug'     => $slug,
			);

		case 'custom':
			$template = (string) ( $settings['custom_url'] ?? '' );
			$href     = str_replace( array( '{slug}', '{title}' ), array( rawurlencode( $slug ), rawurlencode( $title ) ), $template );
			if ( '' === trim( $href ) ) {
				return agend_apps_records_record_link_none( $ctx );
			}
			return array(
				'reason'   => '',
				'ctx'      => $ctx,
				'kind'     => 'anchor',
				'action'   => $action,
				'href'     => $href,
				'label'    => '' !== $text ? $text : $title,
				'new_tab'  => 'yes' === ( $settings['new_tab'] ?? '' ),
				'disabled' => false,
				'slug'     => $slug,
			);

		case 'enrol':
			if ( 'course' !== $ctx['type'] ) {
				return agend_apps_records_record_link_none( $ctx, 'enrol_wrong_type' );
			}
			if ( 'yes' === ( $settings['hide_when_enrolled'] ?? 'yes' ) && ! empty( $record['my_enrollment'] ) ) {
				return agend_apps_records_record_link_none( $ctx );
			}
			return array(
				'reason'   => '',
				'ctx'      => $ctx,
				'kind'     => 'anchor',
				'action'   => $action,
				// Mirrors the built-in detail: enrolment starts from a member
				// sign-in that returns to this course.
				'href'     => wp_login_url( agend_apps_records_record_link_detail_url( $ctx ) ),
				'label'    => '' !== $text ? $text : __( 'Enrol Now', 'agend-apps-core' ),
				'new_tab'  => false,
				'disabled' => false,
				'slug'     => $slug,
			);

		case 'register':
			if ( 'event' !== $ctx['type'] || '' === $slug ) {
				return agend_apps_records_record_link_none( $ctx, 'register_wrong_type' );
			}
			$registered = ! empty( $record['my_registration'] );
			if ( $registered && 'yes' === ( $settings['hide_when_registered'] ?? '' ) ) {
				return agend_apps_records_record_link_none( $ctx );
			}
			$sold_out = ! empty( $record['sold_out'] );
			$label    = $text;
			if ( '' === $label ) {
				$label = $sold_out
					? __( 'Sold Out', 'agend-apps-core' )
					: ( $registered ? __( 'Register Another Attendee', 'agend-apps-core' ) : __( 'Register Now', 'agend-apps-core' ) );
			}
			return array(
				'reason'   => '',
				'ctx'      => $ctx,
				'kind'     => 'button',
				'action'   => $action,
				'href'     => '',
				'label'    => $label,
				'new_tab'  => false,
				'disabled' => $sold_out && 'yes' === ( $settings['disabled_when_sold_out'] ?? 'yes' ),
				'slug'     => $slug,
			);
	}

	// An action outside the schema's own option list; nothing to render and
	// nothing more specific to say than "renders nothing".
	return agend_apps_records_record_link_none( $ctx );
}

/**
 * Why the record link with these settings renders nothing, or '' when it
 * renders. See agend_apps_records_record_link_resolve().
 *
 * @param array $settings Surface settings.
 * @param array $opts     See agend_apps_records_record_link_resolve().
 * @return string '', 'ical_wrong_type', 'enrol_wrong_type', or 'register_wrong_type'.
 */
function agend_apps_records_record_link_render_reason( array $settings, array $opts = array() ): string {
	return agend_apps_records_record_link_resolve( $settings, $opts )['reason'];
}

/**
 * Renders the record link surface: an anchor, a `<span>` inside a card that
 * is itself one big link, or (the `register` action) a button that a
 * catalogue's own script hydrates via its `data-agend-event-slug` attribute.
 *
 * @param array $settings Surface settings (see agend_apps_records_surface_schema( 'record-link' )).
 * @param array $opts     'preview' (bool) / 'preview_type' (string): passed
 *                        straight to agend_apps_records_resolve_record_context().
 * @return string The rendered markup, or '' when there is nothing to render
 *                (see agend_apps_records_record_link_render_reason() for why,
 *                when a caller needs to know).
 */
function agend_apps_records_render_record_link( array $settings, array $opts = array() ): string {
	$resolved = agend_apps_records_record_link_resolve( $settings, $opts );

	if ( 'button' === $resolved['kind'] ) {
		$classes = agend_apps_records_record_link_classes( $settings, 'action-' . $resolved['action'] );
		// Never inside a card link: the button's own click must win, so the
		// card wrapper skips clicks that land on [data-agend-event-slug].
		return '<button type="button" class="' . esc_attr( $classes ) . '" data-agend-event-slug="' . esc_attr( $resolved['slug'] ) . '"' . ( $resolved['disabled'] ? ' disabled' : '' ) . '>' . esc_html( $resolved['label'] ) . '</button>';
	}

	if ( 'anchor' === $resolved['kind'] ) {
		return agend_apps_records_record_link_output_anchor( $resolved['href'], $resolved['label'], $settings, $resolved['ctx'], $resolved['action'], $resolved['new_tab'] );
	}

	return '';
}
