<?php
/**
 * Server render of the Member Summary surface: a signed-in member's own
 * membership, CPD points, upcoming events, certificates and badges, and
 * directory listing.
 *
 * Page-builder agnostic: an adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema( 'member-summary' )`) and echoes the
 * returned HTML.
 *
 * Personal content is never in the page. The page carries a neutral shell
 * (the heading and a loading state), which is the same for every visitor and so
 * safe in any page cache, and a script that asks `agend-apps/v1/member/summary`
 * for the member's own cards. That answer is `no-store` and varies by cookie.
 * The cards are drawn here, in PHP, by
 * {@see agend_apps_records_member_summary_render_body()}, and sent as a
 * fragment: one set of markup, escaped in one place, which the editors' example
 * and the tests use as well. The script only places the fragment and runs the
 * waiting and retrying.
 *
 * A visitor who is not signed in gets a sign-in card, drawn on the server so it
 * needs no script. Nothing sample-shaped is ever drawn on the front end: the
 * labelled example is for the editor, behind the `preview` option.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';

// The item limit lives with the settings that expose it.
require_once __DIR__ . '/../schema/member-summary.php';

// Colours: the shared resolver and the site palette.
require_once __DIR__ . '/../palette.php';

// Links are read through the shared normaliser.
require_once __DIR__ . '/../format.php';

// The access vocabulary and the data the fragment is drawn from.
require_once __DIR__ . '/../../member-access-state.php';
require_once __DIR__ . '/../../member-summary.php';

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

/**
 * The settings that decide what the cards say and which are drawn. These are
 * the ones the browser hands back with each fetch, so the fragment can be drawn
 * for this instance without the server having to find it again. Colours and
 * layout stay on the page.
 *
 * @return string[]
 */
function agend_apps_records_member_summary_content_keys(): array {
	return array(
		'show_greeting',
		'show_membership',
		'show_cpd',
		'show_events',
		'show_courses',
		'show_credentials',
		'show_directory',
		'show_reading',
		'max_events',
		'max_courses',
		'max_certificates',
		'membership_url',
		'join_url',
		'courses_url',
		'resources_url',
		'credential_check_url',
		'my_listing_url',
		'support_url',
		'signed_out_message',
		'sign_in_label',
		'hide_when_signed_out',
		'empty_events_message',
		'empty_courses_message',
		'empty_reading_message',
		'empty_credentials_message',
		'no_record_message',
		'unavailable_message',
	);
}

/**
 * The content settings as plain values, for the page to hold and the browser to
 * send back: only the known keys, URLs as strings (Elementor stores a link as an
 * array), everything else as it was.
 *
 * @param array $settings Surface settings.
 * @return array<string, mixed>
 */
function agend_apps_records_member_summary_client_settings( array $settings ): array {
	$out = array();

	foreach ( agend_apps_records_member_summary_content_keys() as $key ) {
		if ( ! array_key_exists( $key, $settings ) ) {
			continue;
		}

		$value = $settings[ $key ];

		if ( '_url' === substr( $key, -4 ) ) {
			$value = agend_apps_records_normalise_url_setting( $value );
		}

		$out[ $key ] = is_scalar( $value ) || null === $value ? $value : '';
	}

	return $out;
}

/**
 * Builds what the cards need from the content settings, typed and bounded.
 *
 * This is also what the route runs on whatever the browser sends back, so it is
 * the one place that decides what a value may be: a toggle is a yes or no, a
 * count stays in its range, a link is a clean address, and nothing is trusted
 * for being in a saved page.
 *
 * @param array $s Settings (Elementor-shaped: toggles are 'yes'/'').
 * @return array
 */
function agend_apps_records_member_summary_content_config( array $s ): array {
	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		$value = $s[ $key ] ?? $fallback;

		return 'yes' === $value || true === $value || '1' === $value || 1 === $value;
	};

	$text = static function ( string $key, string $fallback ) use ( $s ): string {
		return array_key_exists( $key, $s ) ? trim( wp_strip_all_tags( is_scalar( $s[ $key ] ) ? (string) $s[ $key ] : '' ) ) : $fallback;
	};

	$url = static function ( string $key ) use ( $s ): string {
		$value = agend_apps_records_normalise_url_setting( $s[ $key ] ?? '' );

		return '' === trim( $value ) ? '' : esc_url_raw( trim( $value ) );
	};

	$max = static function ( string $key ) use ( $s ): int {
		return max( 1, min( AGEND_APPS_RECORDS_MEMBER_SUMMARY_MAX_ITEMS, (int) ( $s[ $key ] ?? 3 ) ) );
	};

	return array(
		'greeting'        => $on( 'show_greeting' ),
		'sections'        => array(
			'membership'  => $on( 'show_membership' ),
			'cpd'         => $on( 'show_cpd' ),
			'events'      => $on( 'show_events' ),
			'courses'     => $on( 'show_courses' ),
			'credentials' => $on( 'show_credentials' ),
			'directory'   => $on( 'show_directory' ),
			// Off unless the editor turns it on: it needs the resources library.
			'reading'     => $on( 'show_reading', '' ),
		),
		'limits'          => array(
			'events'       => $max( 'max_events' ),
			'courses'      => $max( 'max_courses' ),
			'certificates' => $max( 'max_certificates' ),
			// Not a setting: a handful of lists is a glance, the library is the place to browse.
			'reading'      => 4,
		),
		'links'           => array(
			'membership'       => $url( 'membership_url' ),
			'join'             => $url( 'join_url' ),
			'courses'          => $url( 'courses_url' ),
			'resources'        => $url( 'resources_url' ),
			'credential_check' => $url( 'credential_check_url' ),
			'my_listing'       => $url( 'my_listing_url' ),
			'support'          => $url( 'support_url' ),
		),
		'copy'            => array(
			'signed_out'  => $text( 'signed_out_message', __( 'Your membership, CPD points, events and certificates appear here once you sign in.', 'agend-apps-core' ) ),
			'sign_in'     => $text( 'sign_in_label', __( 'Sign in', 'agend-apps-core' ) ),
			'no_events'   => $text( 'empty_events_message', __( 'You are not registered for any upcoming events.', 'agend-apps-core' ) ),
			'no_courses'  => $text( 'empty_courses_message', __( 'You have no courses in progress.', 'agend-apps-core' ) ),
			'no_reading'  => $text( 'empty_reading_message', __( 'There are no reading lists for you yet.', 'agend-apps-core' ) ),
			'no_credentials' => $text( 'empty_credentials_message', __( 'Certificates and badges you earn will appear here.', 'agend-apps-core' ) ),
			'no_record'   => $text( 'no_record_message', '' ),
			'unavailable' => $text( 'unavailable_message', '' ),
		),
		'hideWhenSignedOut' => $on( 'hide_when_signed_out', '' ),
	);
}

/**
 * Builds the full render config from the surface settings: the content config,
 * the heading, the colours and the card corner.
 *
 * @param array $s Surface settings.
 * @return array
 */
function agend_apps_records_member_summary_build_config( array $s ): array {
	$colours = agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $s['inherit_colours'] ?? 'yes' ) ) + $s,
		array(
			'heading' => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['heading'],
			'body'    => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'],
			'accent'  => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['accent'],
		)
	);

	return agend_apps_records_member_summary_content_config( $s ) + array(
		'heading'    => array(
			'show'  => 'yes' === ( $s['show_heading'] ?? 'yes' ),
			'title' => array_key_exists( 'heading_text', $s ) ? trim( (string) $s['heading_text'] ) : __( 'My membership', 'agend-apps-core' ),
		),
		'cardRadius' => max( 0, min( 48, (int) ( $s['card_radius'] ?? 10 ) ) ),
		'colours'    => $colours['colours'],
		'theme'      => array(
			'colourSource' => $colours['source'],
		),
	);
}

/**
 * The colour and corner CSS variables the stylesheet reads.
 *
 * @param array $config The render config.
 * @return string
 */
function agend_apps_records_member_summary_inline_style( array $config ): string {
	return sprintf(
		'--agend-ms-heading:%1$s;--agend-ms-body:%2$s;--agend-ms-accent:%3$s;--agend-ms-card-radius:%4$dpx;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] ),
		(int) $config['cardRadius']
	);
}

// ---------------------------------------------------------------------------
// Where the links go
// ---------------------------------------------------------------------------

/**
 * The member's own screens in the portal, under the account's home.
 *
 * @param string $portal The account's portal home, '' when it is not known.
 * @return array<string, string> Screen name to address; every address '' when
 *                               the portal home is not known.
 */
function agend_apps_records_member_summary_portal_screens( string $portal ): array {
	$paths = array(
		'membership'   => 'membership',
		'cpd'          => 'cpd',
		'events'       => 'events',
		'certificates' => 'certificates',
		'badges'       => 'cpd/badges',
	);

	$screens = array();

	foreach ( $paths as $name => $path ) {
		$screens[ $name ] = '' !== $portal ? rtrim( $portal, '/' ) . '/' . $path : '';
	}

	return $screens;
}

/**
 * Where each card sends the member.
 *
 * The member's own screens are in the portal, under the account's home. Those
 * are only built when the account is known: without it the portal home is the
 * portal's front page, and a path under that goes nowhere. An editor's link
 * wins over the portal's for the membership. The pages of the site itself (an
 * event's page, a listing's page) are the catalogue pages set under Catalogue
 * Pages, resolved by {@see agend_apps_records_member_summary_event_url()} and
 * {@see agend_apps_records_member_summary_listing_url()}.
 *
 * @param array $config The render config.
 * @return array<string, string> Empty strings where there is no link to give.
 */
function agend_apps_records_member_summary_links( array $config ): array {
	$portal = '';

	if ( class_exists( 'Agend_Apps_Settings' ) && method_exists( 'Agend_Apps_Settings', 'get_account_slug' ) && '' !== Agend_Apps_Settings::get_account_slug() && method_exists( 'Agend_Apps_Settings', 'get_portal_home_url' ) ) {
		$portal = Agend_Apps_Settings::get_portal_home_url();
	}

	$screens = agend_apps_records_member_summary_portal_screens( $portal );

	$links = array(
		'membership'       => '' !== $config['links']['membership'] ? $config['links']['membership'] : $screens['membership'],
		'cpd'              => $screens['cpd'],
		'events'           => $screens['events'],
		'certificates'     => $screens['certificates'],
		'badges'           => $screens['badges'],
		'join'             => $config['links']['join'],
		'courses'          => '' !== $config['links']['courses'] ? $config['links']['courses'] : agend_apps_records_member_summary_courses_page_url(),
		'resources'        => '' !== $config['links']['resources'] ? $config['links']['resources'] : agend_apps_records_member_summary_resources_url(),
		'credential_check' => $config['links']['credential_check'],
		'my_listing'       => $config['links']['my_listing'],
		'support'          => $config['links']['support'],
	);

	/**
	 * Filters where the member summary's cards link to.
	 *
	 * @param array<string, string> $links  Link name to address; '' for none.
	 * @param array                 $config The render config.
	 */
	return (array) apply_filters( 'agend_apps_member_summary_links', $links, $config );
}

/**
 * The address of an event's page on this site, or '' when no Events page is set.
 *
 * @param string $slug The event's slug.
 * @return string
 */
function agend_apps_records_member_summary_event_url( string $slug ): string {
	if ( '' === $slug || ! class_exists( 'Agend_Apps_Records_Pages' ) || 0 === Agend_Apps_Records_Pages::page_id( 'event' ) ) {
		return '';
	}

	return Agend_Apps_Records_Pages::detail_url( 'event', $slug );
}

/**
 * The address of a course's page on this site, or '' when no Courses page is set.
 *
 * @param string $slug The course's slug.
 * @return string
 */
function agend_apps_records_member_summary_course_url( string $slug ): string {
	if ( '' === $slug || ! class_exists( 'Agend_Apps_Records_Pages' ) || 0 === Agend_Apps_Records_Pages::page_id( 'course' ) ) {
		return '';
	}

	return Agend_Apps_Records_Pages::detail_url( 'course', $slug );
}

/**
 * The site's own Courses page, where "Continue learning" goes unless the editor
 * chose another page, or '' when none is set.
 *
 * @return string
 */
function agend_apps_records_member_summary_courses_page_url(): string {
	return class_exists( 'Agend_Apps_Records_Pages' ) ? Agend_Apps_Records_Pages::page_url( 'course' ) : '';
}

/**
 * Where "Browse resources" goes unless the editor chose a page: the public
 * resources library, when the site is connected to one, else nowhere.
 *
 * @return string
 */
function agend_apps_records_member_summary_resources_url(): string {
	return function_exists( 'agend_apps_atlas_public_search_url' ) ? agend_apps_atlas_public_search_url() : '';
}

/**
 * The address of a directory listing's page on this site, or '' when no
 * Directory page is set.
 *
 * @param string $slug The listing's slug.
 * @return string
 */
function agend_apps_records_member_summary_listing_url( string $slug ): string {
	if ( '' === $slug || ! class_exists( 'Agend_Apps_Records_Pages' ) || 0 === Agend_Apps_Records_Pages::page_id( 'listing' ) ) {
		return '';
	}

	return Agend_Apps_Records_Pages::detail_url( 'listing', $slug );
}

/**
 * Where a certificate's check page is, with the certificate's code on it, or ''.
 *
 * @param string $page The credential check page.
 * @param string $code The certificate's verification code.
 * @return string
 */
function agend_apps_records_member_summary_check_url( string $page, string $code ): string {
	if ( '' === $page || '' === $code ) {
		return '';
	}

	return add_query_arg( 'agend_code', rawurlencode( $code ), $page );
}

// ---------------------------------------------------------------------------
// Small pieces of markup
// ---------------------------------------------------------------------------

/**
 * A number of points as a person writes it: no trailing zero, one decimal at
 * most.
 *
 * @param float $points Points.
 * @return string
 */
function agend_apps_records_member_summary_points( float $points ): string {
	return number_format_i18n( $points, abs( $points - round( $points ) ) < 0.005 ? 0 : 1 );
}

/**
 * A gateway timestamp in the site's time zone, or ''.
 *
 * @param mixed  $iso    An ISO 8601 date or datetime.
 * @param string $format A PHP date format.
 * @return string
 */
function agend_apps_records_member_summary_date( $iso, string $format ): string {
	$time = is_string( $iso ) && '' !== $iso ? strtotime( $iso ) : false;

	if ( false === $time ) {
		return '';
	}

	$formatted = wp_date( $format, (int) $time );

	return is_string( $formatted ) ? $formatted : '';
}

/**
 * The days-to-go phrase for a renewal or an end.
 *
 * @param int $days Days from today; negative once it has passed.
 * @return string
 */
function agend_apps_records_member_summary_days_phrase( int $days ): string {
	if ( 0 === $days ) {
		return __( 'today', 'agend-apps-core' );
	}

	if ( 1 === $days ) {
		return __( 'tomorrow', 'agend-apps-core' );
	}

	if ( $days > 1 ) {
		/* translators: %s: number of days. */
		return sprintf( __( 'in %s days', 'agend-apps-core' ), number_format_i18n( $days ) );
	}

	/* translators: %s: number of days. */
	return sprintf( _n( '%s day ago', '%s days ago', abs( $days ), 'agend-apps-core' ), number_format_i18n( abs( $days ) ) );
}

/**
 * The chevron on a whole-row link. Decorative: the row's text is the link's.
 *
 * @return string
 */
function agend_apps_records_member_summary_chevron(): string {
	return '<svg class="agend-ms-chevron" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6 3.5 10.5 8 6 12.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/**
 * A small line icon, drawn in the text colour. Decorative: the words beside it
 * always say the same thing.
 *
 * @param string $name Icon name; an unknown name draws nothing.
 * @param string $size Pixel size, square.
 * @return string
 */
function agend_apps_records_member_summary_icon( string $name, string $size = '20' ): string {
	$paths = array(
		'cpd'         => '<path d="M4 16.5a8 8 0 1 1 16 0"/><path d="m12 16.5 3.6-5"/><circle cx="12" cy="16.5" r="1.1"/>',
		'events'      => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
		'courses'     => '<path d="M3 6.5c3-1.3 6-1.3 9 .5 3-1.8 6-1.8 9-.5V19c-3-1.3-6-1.3-9 .5-3-1.8-6-1.8-9-.5z"/><path d="M12 7v12.5"/>',
		'credentials' => '<circle cx="12" cy="9" r="5.5"/><path d="M8.5 13.5 7 21l5-2.5 5 2.5-1.5-7.5"/>',
		'directory'   => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16.2c.7-1.6 2-2.4 3.2-2.4s2.5.8 3.2 2.4M14.5 10h4M14.5 13.5h3"/>',
		'reading'     => '<path d="M6 3.5h12a1 1 0 0 1 1 1V21l-7-4-7 4V4.5a1 1 0 0 1 1-1z"/>',
		'membership'  => '<path d="M12 3.5 14.6 9l5.9.7-4.4 4 1.2 5.8L12 16.6 6.7 19.5l1.2-5.8-4.4-4L9.4 9z"/>',
		'person'      => '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.5 20c.9-4 4-6 7.5-6s6.6 2 7.5 6"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return '<svg class="agend-ms-icon" width="' . esc_attr( $size ) . '" height="' . esc_attr( $size ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

/**
 * A button-looking link.
 *
 * @param string $href    Address.
 * @param string $label   Text.
 * @param string $variant '' for the main button, `quiet` for a second one, `on-band` for one on the welcome band.
 * @return string
 */
function agend_apps_records_member_summary_button( string $href, string $label, string $variant = '' ): string {
	return '<a class="agend-ms-button exclude-agend-theme-link' . ( '' !== $variant ? ' agend-ms-button--' . esc_attr( $variant ) : '' ) . '" href="' . esc_url( $href ) . '">' . esc_html( $label ) . '</a>';
}

/**
 * The "try again" button the script listens for.
 *
 * @param string $label Text.
 * @return string
 */
function agend_apps_records_member_summary_retry_button( string $label ): string {
	return '<button type="button" class="agend-ms-button agend-ms-button--quiet" data-agend-ms-retry>' . esc_html( $label ) . '</button>';
}

/**
 * The names of the tiles, as a member reads them. One place, so the card heads,
 * the sign-in invitation and the administrators' notes say the same thing.
 *
 * @return array<string, string>
 */
function agend_apps_records_member_summary_part_names(): array {
	return array(
		'membership'  => __( 'Membership', 'agend-apps-core' ),
		'cpd'         => __( 'CPD points', 'agend-apps-core' ),
		'events'      => __( 'Upcoming events', 'agend-apps-core' ),
		'courses'     => __( 'Courses in progress', 'agend-apps-core' ),
		'credentials' => __( 'Certificates and badges', 'agend-apps-core' ),
		'directory'   => __( 'Directory listing', 'agend-apps-core' ),
		'reading'     => __( 'Reading lists', 'agend-apps-core' ),
	);
}

/**
 * A tile: an icon and a name, a body, and one quiet link at its foot.
 *
 * The foot is drawn only when there is a link to put in it. Every tile in a row
 * is as tall as the tallest, and the foot sits at the bottom of each, so the
 * links line up whatever the tiles hold.
 *
 * @param string $key    Part name, for the stylesheet and the icon.
 * @param string $title  Heading.
 * @param string $body   Markup, already escaped.
 * @param string $footer Markup, already escaped.
 * @return string
 */
function agend_apps_records_member_summary_card( string $key, string $title, string $body, string $footer = '' ): string {
	return '<section class="agend-ms-card agend-ms-card--' . esc_attr( $key ) . '">'
		. '<div class="agend-ms-card__head"><span class="agend-ms-chip">' . agend_apps_records_member_summary_icon( $key ) . '</span><h3 class="agend-ms-card__title">' . esc_html( $title ) . '</h3></div>'
		. '<div class="agend-ms-card__body">' . $body . '</div>'
		. ( '' !== $footer ? '<div class="agend-ms-card__foot">' . $footer . '</div>' : '' )
		. '</section>';
}

/**
 * The quiet link at the foot of a tile.
 *
 * @param string $href  Address; nothing is drawn when empty.
 * @param string $label Text.
 * @return string
 */
function agend_apps_records_member_summary_more( string $href, string $label ): string {
	if ( '' === $href ) {
		return '';
	}

	return '<a class="agend-ms-more exclude-agend-theme-link" href="' . esc_url( $href ) . '"><span>' . esc_html( $label ) . '</span>' . agend_apps_records_member_summary_chevron() . '</a>';
}

/**
 * The message a tile shows when its own read failed.
 *
 * @return string
 */
function agend_apps_records_member_summary_tile_unavailable(): string {
	return '<p class="agend-ms-quiet">' . esc_html__( 'We could not load this just now.', 'agend-apps-core' ) . '</p>';
}

/**
 * A name as a whole-row link or plain text.
 *
 * @param string $title Text.
 * @param string $url   Address, '' for plain text.
 * @return string
 */
function agend_apps_records_member_summary_row_title( string $title, string $url ): string {
	return '' !== $url
		? '<a class="agend-ms-row__link exclude-agend-theme-link" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>'
		: '<span class="agend-ms-row__title">' . esc_html( $title ) . '</span>';
}

// ---------------------------------------------------------------------------
// The welcome band
// ---------------------------------------------------------------------------

/**
 * The avatar: the member's initials, or a person when there is no name to take
 * them from.
 *
 * @param string $initials Up to two letters.
 * @return string
 */
function agend_apps_records_member_summary_avatar( string $initials ): string {
	return '<span class="agend-ms-avatar" aria-hidden="true">' . ( '' !== $initials ? '<span class="agend-ms-avatar__letters">' . esc_html( $initials ) . '</span>' : agend_apps_records_member_summary_icon( 'person', '26' ) ) . '</span>';
}

/**
 * The welcome band across the top of a signed-in member's view.
 *
 * The greeting and the initials come from the contact, the pills, renewal line
 * and "Manage membership" link from the membership part. Either may be missing
 * (switched off, or not known) and the band is drawn from what is there; with
 * neither it is not drawn at all. A membership that could not be read says so
 * here and is never drawn as "no membership".
 *
 * @param array $config  The render config.
 * @param array $contact The contact: `first_name`, `initials`, `title`, `company`.
 * @param array $part    The membership part, or an empty array when it is off.
 * @param array $links   Where the cards link to.
 * @return string
 */
function agend_apps_records_member_summary_render_hero( array $config, array $contact, array $part, array $links ): string {
	$first = (string) ( $contact['first_name'] ?? '' );
	$named = $config['greeting'] && '' !== $first;
	$shows = array() !== $part && 'no_access' !== ( $part['status'] ?? '' );

	if ( ! $named && ! $shows ) {
		return '';
	}

	/* translators: %s: the member's first name. */
	$title = $named ? sprintf( __( 'Welcome back, %s', 'agend-apps-core' ), $first ) : __( 'Your membership', 'agend-apps-core' );

	$role = '';

	if ( $named ) {
		$role = implode( ', ', array_filter( array( (string) ( $contact['title'] ?? '' ), (string) ( $contact['company'] ?? '' ) ), 'strlen' ) );
	}

	$pills  = '';
	$meta   = '';
	$note   = '';
	$action = '';
	$state  = '';

	if ( $shows && 'ready' === $part['status'] ) {
		$labels = array(
			'active'  => __( 'Active', 'agend-apps-core' ),
			'pending' => __( 'Pending', 'agend-apps-core' ),
			'ended'   => __( 'Ended', 'agend-apps-core' ),
		);
		$state  = (string) $part['label'];

		if ( '' !== $part['tier'] ) {
			$pills .= '<li class="agend-ms-pill agend-ms-pill--tier">' . esc_html( $part['tier'] ) . '</li>';
		}

		$pills .= '<li class="agend-ms-pill agend-ms-pill--status agend-ms-pill--' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ?? $labels['active'] ) . '</li>';

		$date_lines = array(
			'renews'  => __( 'Renews on', 'agend-apps-core' ),
			'expires' => __( 'Expires on', 'agend-apps-core' ),
			'ended'   => __( 'Ended on', 'agend-apps-core' ),
		);
		$date       = agend_apps_records_member_summary_date( $part['date'], 'j F Y' );

		if ( '' !== $date && isset( $date_lines[ $part['kind'] ] ) ) {
			$meta .= '<li class="agend-ms-meta__item">' . esc_html( $date_lines[ $part['kind'] ] ) . '&nbsp;<time datetime="' . esc_attr( substr( (string) $part['date'], 0, 10 ) ) . '">' . esc_html( $date ) . '</time></li>';

			if ( null !== $part['days'] ) {
				$meta .= '<li class="agend-ms-meta__item">' . esc_html( agend_apps_records_member_summary_days_phrase( (int) $part['days'] ) ) . '</li>';
			}
		}

		if ( $part['via_seat'] ) {
			$note = '<p class="agend-ms-hero__note">' . esc_html__( 'Included through your organisation\'s membership.', 'agend-apps-core' ) . '</p>';
		}

		$button_labels = array(
			'active'  => __( 'Manage membership', 'agend-apps-core' ),
			'pending' => __( 'View membership', 'agend-apps-core' ),
			'ended'   => __( 'Renew membership', 'agend-apps-core' ),
		);

		if ( '' !== $links['membership'] ) {
			$action = agend_apps_records_member_summary_button( $links['membership'], $button_labels[ $state ] ?? $button_labels['active'], 'on-band' );
		}
	} elseif ( $shows && 'empty' === $part['status'] ) {
		$state = 'none';
		$note  = '<p class="agend-ms-hero__note">' . esc_html__( 'There is no membership on this account.', 'agend-apps-core' ) . ' ' . esc_html__( 'If you think this is wrong, get in touch and we will look into it.', 'agend-apps-core' ) . '</p>';

		if ( '' !== $links['join'] ) {
			$action .= agend_apps_records_member_summary_button( $links['join'], __( 'Join', 'agend-apps-core' ), 'on-band' );
		}

		if ( '' !== $links['support'] ) {
			$action .= agend_apps_records_member_summary_button( $links['support'], __( 'Contact us', 'agend-apps-core' ), 'on-band-quiet' );
		}
	} elseif ( $shows ) {
		$state = 'unavailable';
		$note  = '<p class="agend-ms-hero__note">' . esc_html__( 'We could not load your membership just now.', 'agend-apps-core' ) . '</p>';
	}

	return '<header class="agend-ms-hero' . ( '' !== $state ? ' agend-ms-hero--' . esc_attr( $state ) : '' ) . '">'
		. agend_apps_records_member_summary_avatar( $named ? (string) ( $contact['initials'] ?? '' ) : '' )
		. '<div class="agend-ms-hero__main">'
		. '<h3 class="agend-ms-hero__title">' . esc_html( $title ) . '</h3>'
		. ( '' !== $role ? '<p class="agend-ms-hero__role">' . esc_html( $role ) . '</p>' : '' )
		. ( '' !== $meta ? '<ul class="agend-ms-meta agend-ms-hero__meta" role="list">' . $meta . '</ul>' : '' )
		. $note
		. '</div>'
		. ( '' !== $pills || '' !== $action ? '<div class="agend-ms-hero__side">' . ( '' !== $pills ? '<ul class="agend-ms-pills" role="list">' . $pills . '</ul>' : '' ) . ( '' !== $action ? '<p class="agend-ms-hero__actions">' . $action . '</p>' : '' ) . '</div>' : '' )
		. '</header>';
}

// ---------------------------------------------------------------------------
// The tiles
// ---------------------------------------------------------------------------

/**
 * The CPD points tile.
 *
 * With a target Agend resolved, a ring: how far round it is says how far the
 * member is to their own target, and the words say the same, because the ring is
 * a picture of them and a screen reader is read the sentence instead. Without a
 * target the member's points are shown on their own and the tile says why there
 * is no ring. A target is never invented: the ring exists only where Agend gave
 * one.
 *
 * @param array $part  The CPD part.
 * @param array $links Where the cards link to.
 * @return string
 */
function agend_apps_records_member_summary_render_cpd( array $part, array $links ): string {
	$title  = __( 'CPD points', 'agend-apps-core' );
	$footer = agend_apps_records_member_summary_more( $links['cpd'], __( 'View your CPD record', 'agend-apps-core' ) );

	if ( 'unavailable' === $part['status'] ) {
		return agend_apps_records_member_summary_card( 'cpd', $title, agend_apps_records_member_summary_tile_unavailable(), $footer );
	}

	if ( 'empty' === $part['status'] ) {
		return agend_apps_records_member_summary_card( 'cpd', $title, '<p class="agend-ms-quiet">' . esc_html__( 'No CPD points are recorded for you yet.', 'agend-apps-core' ) . '</p>', $footer );
	}

	$completed = null !== ( $part['completed'] ?? null ) && (int) $part['completed'] > 0
		/* translators: %s: number of courses the member has completed. */
		? '<p class="agend-ms-quiet">' . esc_html( sprintf( _n( '%s course completed', '%s courses completed', (int) $part['completed'], 'agend-apps-core' ), number_format_i18n( (int) $part['completed'] ) ) ) . '</p>'
		: '';

	if ( 'total' === $part['form'] ) {
		$why  = 'not_applicable' === ( $part['why'] ?? '' ) ? __( 'No CPD target applies to you.', 'agend-apps-core' ) : __( 'No CPD target has been set for you.', 'agend-apps-core' );
		$body = '<p class="agend-ms-figure"><span class="agend-ms-figure__value">' . esc_html( agend_apps_records_member_summary_points( (float) $part['earned'] ) ) . '</span> <span class="agend-ms-figure__unit">' . esc_html__( 'points earned', 'agend-apps-core' ) . '</span></p>'
			. '<p class="agend-ms-quiet">' . esc_html( $why ) . '</p>' . $completed;

		return agend_apps_records_member_summary_card( 'cpd', $title, $body, $footer );
	}

	$earned = (float) $part['earned'];
	$target = (float) $part['target'];
	$fill   = max( 0.0, min( 100.0, $target > 0 ? $earned / $target * 100 : 0.0 ) );
	$circ   = 326.73; // The circumference of the ring's circle (radius 52).
	$to_go  = max( 0.0, $target - $earned );

	$paces = array(
		'met'      => __( 'Target met', 'agend-apps-core' ),
		'on_track' => __( 'On track', 'agend-apps-core' ),
		'behind'   => __( 'Behind pace', 'agend-apps-core' ),
	);

	$meta = array();

	if ( '' !== $part['pace'] && isset( $paces[ $part['pace'] ] ) ) {
		$meta[] = '<li class="agend-ms-meta__item agend-ms-meta__item--pace">' . esc_html( $paces[ $part['pace'] ] ) . '</li>';
	}

	if ( null !== $part['days_left'] ) {
		/* translators: %s: number of days. */
		$meta[] = '<li class="agend-ms-meta__item">' . esc_html( sprintf( _n( '%s day left', '%s days left', (int) $part['days_left'], 'agend-apps-core' ), number_format_i18n( (int) $part['days_left'] ) ) ) . '</li>';
	}

	/* translators: 1: points earned, 2: the member's CPD target. */
	$sentence = sprintf( __( '%1$s of %2$s points earned.', 'agend-apps-core' ), agend_apps_records_member_summary_points( $earned ), agend_apps_records_member_summary_points( $target ) );

	if ( $to_go > 0 ) {
		/* translators: %s: points still to earn to reach the target. */
		$lead = sprintf( __( '%s points to your target', 'agend-apps-core' ), agend_apps_records_member_summary_points( $to_go ) );
	} else {
		$lead = __( 'You have reached your target', 'agend-apps-core' );
	}

	$ring = '<div class="agend-ms-ring" style="--agend-ms-fill:' . esc_attr( number_format( $fill, 1, '.', '' ) ) . '%">'
		. '<svg class="agend-ms-ring__svg" viewBox="0 0 120 120" width="112" height="112" aria-hidden="true" focusable="false">'
		. '<circle class="agend-ms-ring__track" cx="60" cy="60" r="52" fill="none" stroke-width="10"/>'
		. '<circle class="agend-ms-ring__fill" cx="60" cy="60" r="52" fill="none" stroke-width="10" stroke-linecap="round" stroke-dasharray="' . esc_attr( number_format( $circ * $fill / 100, 2, '.', '' ) ) . ' ' . esc_attr( number_format( $circ, 2, '.', '' ) ) . '" transform="rotate(-90 60 60)"/>'
		. '</svg>'
		. '<span class="agend-ms-ring__text" aria-hidden="true"><span class="agend-ms-ring__value">' . esc_html( agend_apps_records_member_summary_points( $earned ) ) . '</span>'
		/* translators: %s: the member's CPD target. */
		. '<span class="agend-ms-ring__of">' . esc_html( sprintf( __( 'of %s points', 'agend-apps-core' ), agend_apps_records_member_summary_points( $target ) ) ) . '</span></span>'
		. '</div>';

	$side = '<p class="agend-ms-lead">' . esc_html( $lead ) . '</p>'
		. ( array() !== $meta ? '<ul class="agend-ms-meta" role="list">' . implode( '', $meta ) . '</ul>' : '' );

	$body = '<div class="agend-ms-cpd">' . $ring . '<div class="agend-ms-cpd__text">' . $side . '</div></div>'
		. '<p class="agend-ms-sr">' . esc_html( $sentence ) . '</p>';

	$ends = agend_apps_records_member_summary_date( $part['period_end'], 'j F Y' );

	if ( '' !== $ends ) {
		/* translators: %s: the date the CPD period ends. */
		$body .= '<p class="agend-ms-quiet">' . esc_html( sprintf( __( 'This period ends on %s.', 'agend-apps-core' ), $ends ) ) . '</p>';
	}

	if ( '' !== $part['adjustment'] ) {
		/* translators: %s: the reason the member's target differs from the usual one. */
		$body .= '<p class="agend-ms-quiet">' . esc_html( sprintf( __( 'Your target is adjusted: %s.', 'agend-apps-core' ), rtrim( $part['adjustment'], '.' ) ) ) . '</p>';
	}

	$body .= $completed;

	return agend_apps_records_member_summary_card( 'cpd', $title, $body, $footer );
}

/**
 * The upcoming events tile.
 *
 * @param array $part   The events part.
 * @param array $config The render config.
 * @param array $links  Where the cards link to.
 * @return string
 */
function agend_apps_records_member_summary_render_events( array $part, array $config, array $links ): string {
	$title  = __( 'Upcoming events', 'agend-apps-core' );
	$footer = agend_apps_records_member_summary_more( $links['events'], __( 'View all your events', 'agend-apps-core' ) );

	if ( 'unavailable' === $part['status'] ) {
		return agend_apps_records_member_summary_card( 'events', $title, agend_apps_records_member_summary_tile_unavailable(), $footer );
	}

	if ( 'empty' === $part['status'] ) {
		$body = '<p class="agend-ms-quiet">' . esc_html( '' !== $config['copy']['no_events'] ? $config['copy']['no_events'] : __( 'You are not registered for any upcoming events.', 'agend-apps-core' ) ) . '</p>';

		return agend_apps_records_member_summary_card( 'events', $title, $body, $footer );
	}

	$statuses = array(
		'registered' => __( 'Registered', 'agend-apps-core' ),
		'confirmed'  => __( 'Confirmed', 'agend-apps-core' ),
	);

	$rows = '';

	foreach ( $part['items'] as $item ) {
		$url   = agend_apps_records_member_summary_event_url( $item['slug'] );
		$day   = agend_apps_records_member_summary_date( $item['date'], 'j' );
		$month = agend_apps_records_member_summary_date( $item['date'], 'M' );
		$when  = agend_apps_records_member_summary_date( $item['date'], 'l, g:i a' );
		$full  = agend_apps_records_member_summary_date( $item['date'], 'j F Y' );
		$meta  = '';

		// The date marker is for the eye. The line under the title carries the
		// time, and the whole date for a screen reader, which skips the marker.
		if ( '' !== $when ) {
			$meta .= '<li class="agend-ms-meta__item"><span class="agend-ms-sr">' . esc_html( $full ) . ', </span>' . esc_html( $when ) . '</li>';
		}

		if ( isset( $statuses[ $item['status'] ] ) ) {
			$meta .= '<li class="agend-ms-meta__item">' . esc_html( $statuses[ $item['status'] ] ) . '</li>';
		}

		$rows .= '<li class="agend-ms-row' . ( '' !== $url ? ' agend-ms-row--link' : '' ) . '">'
			. '<span class="agend-ms-date" aria-hidden="true">' . ( '' !== $day ? '<span class="agend-ms-date__day">' . esc_html( $day ) . '</span><span class="agend-ms-date__month">' . esc_html( $month ) . '</span>' : '' ) . '</span>'
			. '<span class="agend-ms-row__main">'
			. agend_apps_records_member_summary_row_title( $item['title'], $url )
			. ( '' !== $meta ? '<ul class="agend-ms-meta" role="list">' . $meta . '</ul>' : '' )
			. '</span>'
			. ( '' !== $url ? agend_apps_records_member_summary_chevron() : '' )
			. '</li>';
	}

	$more = '';

	if ( (int) $part['total'] > count( $part['items'] ) ) {
		/* translators: %s: number of further events. */
		$more = '<p class="agend-ms-quiet">' . esc_html( sprintf( _n( 'and %s more.', 'and %s more.', (int) $part['total'] - count( $part['items'] ), 'agend-apps-core' ), number_format_i18n( (int) $part['total'] - count( $part['items'] ) ) ) ) . '</p>';
	}

	return agend_apps_records_member_summary_card( 'events', $title, '<ul class="agend-ms-list" role="list">' . $rows . '</ul>' . $more, $footer );
}

/**
 * The courses in progress tile: each course with a bar and a percentage.
 *
 * The bar is drawn only for a course Agend gave a percentage for. One without is
 * listed by name, with no bar and no number, never as 0.
 *
 * @param array $part   The courses part.
 * @param array $config The render config.
 * @param array $links  Where the cards link to.
 * @return string
 */
function agend_apps_records_member_summary_render_courses( array $part, array $config, array $links ): string {
	$title  = __( 'Courses in progress', 'agend-apps-core' );
	$footer = agend_apps_records_member_summary_more( $links['courses'], __( 'Continue learning', 'agend-apps-core' ) );

	if ( 'unavailable' === $part['status'] ) {
		return agend_apps_records_member_summary_card( 'courses', $title, agend_apps_records_member_summary_tile_unavailable(), $footer );
	}

	if ( 'empty' === $part['status'] ) {
		$body = '<p class="agend-ms-quiet">' . esc_html( '' !== $config['copy']['no_courses'] ? $config['copy']['no_courses'] : __( 'You have no courses in progress.', 'agend-apps-core' ) ) . '</p>';

		return agend_apps_records_member_summary_card( 'courses', $title, $body, $footer );
	}

	$rows = '';

	foreach ( $part['items'] as $item ) {
		$url     = agend_apps_records_member_summary_course_url( $item['slug'] );
		$percent = $item['percent'];
		$shown   = null === $percent ? '' : agend_apps_records_member_summary_points( (float) round( $percent ) );
		$bar     = '';
		$meta    = '';

		if ( null !== $percent ) {
			$bar = '<span class="agend-ms-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $shown ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: course title. */ __( 'Progress in %s', 'agend-apps-core' ), $item['title'] ) ) . '"><span class="agend-ms-bar__fill" style="--agend-ms-fill:' . esc_attr( number_format( $percent, 1, '.', '' ) ) . '%"></span></span>';
		}

		if ( null !== $item['done'] && null !== $item['lessons'] && $item['done'] <= $item['lessons'] ) {
			/* translators: 1: lessons completed, 2: lessons in the course. */
			$meta = '<span class="agend-ms-quiet agend-ms-course__lessons">' . esc_html( sprintf( __( '%1$s of %2$s lessons', 'agend-apps-core' ), number_format_i18n( (int) $item['done'] ), number_format_i18n( (int) $item['lessons'] ) ) ) . '</span>';
		}

		$rows .= '<li class="agend-ms-course' . ( '' !== $url ? ' agend-ms-row--link' : '' ) . '">'
			. '<span class="agend-ms-course__top">' . agend_apps_records_member_summary_row_title( $item['title'], $url )
			. ( '' !== $shown ? '<span class="agend-ms-course__pct">' . esc_html( $shown ) . '%</span>' : '' ) . '</span>'
			. $bar . $meta
			. '</li>';
	}

	$more = '';

	if ( (int) $part['total'] > count( $part['items'] ) ) {
		/* translators: %s: number of further courses. */
		$more = '<p class="agend-ms-quiet">' . esc_html( sprintf( _n( 'and %s more.', 'and %s more.', (int) $part['total'] - count( $part['items'] ), 'agend-apps-core' ), number_format_i18n( (int) $part['total'] - count( $part['items'] ) ) ) ) . '</p>';
	}

	return agend_apps_records_member_summary_card( 'courses', $title, '<ul class="agend-ms-list agend-ms-list--courses" role="list">' . $rows . '</ul>' . $more, $footer );
}

/**
 * The certificates and badges tile.
 *
 * @param array $part   The credentials part.
 * @param array $config The render config.
 * @param array $links  Where the cards link to.
 * @return string
 */
function agend_apps_records_member_summary_render_credentials( array $part, array $config, array $links ): string {
	$title = __( 'Certificates and badges', 'agend-apps-core' );

	if ( 'unavailable' === $part['status'] ) {
		return agend_apps_records_member_summary_card( 'credentials', $title, agend_apps_records_member_summary_tile_unavailable() );
	}

	if ( 'empty' === $part['status'] ) {
		$body = '<p class="agend-ms-quiet">' . esc_html( '' !== $config['copy']['no_credentials'] ? $config['copy']['no_credentials'] : __( 'Certificates and badges you earn will appear here.', 'agend-apps-core' ) ) . '</p>';

		return agend_apps_records_member_summary_card( 'credentials', $title, $body );
	}

	$body = '';

	if ( array() !== $part['certificates'] ) {
		$rows = '';

		foreach ( $part['certificates'] as $certificate ) {
			$url    = agend_apps_records_member_summary_check_url( $links['credential_check'], $certificate['code'] );
			$issued = agend_apps_records_member_summary_date( $certificate['issued'], 'j M Y' );
			$meta   = '';

			if ( '' !== $issued ) {
				/* translators: %s: date a certificate was issued. */
				$meta .= '<li class="agend-ms-meta__item">' . esc_html( sprintf( __( 'Issued %s', 'agend-apps-core' ), $issued ) ) . '</li>';
			}

			if ( null !== $certificate['cpd'] && $certificate['cpd'] > 0 ) {
				/* translators: %s: CPD points a certificate carries. */
				$meta .= '<li class="agend-ms-meta__item">' . esc_html( sprintf( _n( '%s CPD point', '%s CPD points', (int) ceil( $certificate['cpd'] ), 'agend-apps-core' ), agend_apps_records_member_summary_points( (float) $certificate['cpd'] ) ) ) . '</li>';
			}

			$rows .= '<li class="agend-ms-row' . ( '' !== $url ? ' agend-ms-row--link' : '' ) . ' agend-ms-row--plain">'
				. '<span class="agend-ms-row__main">'
				. agend_apps_records_member_summary_row_title( $certificate['title'], $url )
				. ( '' !== $meta ? '<ul class="agend-ms-meta" role="list">' . $meta . '</ul>' : '' )
				. '</span>'
				. ( '' !== $url ? agend_apps_records_member_summary_chevron() : '' )
				. '</li>';
		}

		$body .= '<ul class="agend-ms-list" role="list">' . $rows . '</ul>';
	}

	if ( array() !== $part['badges'] ) {
		$chips = '';

		foreach ( $part['badges'] as $badge ) {
			$dot    = '' !== $badge['colour'] ? ' style="--agend-ms-badge:' . esc_attr( $badge['colour'] ) . '"' : '';
			$chips .= '<li class="agend-ms-badge"><span class="agend-ms-badge__dot"' . $dot . ' aria-hidden="true"></span>' . esc_html( $badge['name'] ) . '</li>';
		}

		$body .= '<ul class="agend-ms-badges" role="list" aria-label="' . esc_attr__( 'Badges', 'agend-apps-core' ) . '">' . $chips . '</ul>';
	}

	// One quiet link: the certificates when there are some, else the badges.
	$footer = array() !== $part['certificates']
		? agend_apps_records_member_summary_more( $links['certificates'], __( 'View all your certificates', 'agend-apps-core' ) )
		: agend_apps_records_member_summary_more( $links['badges'], __( 'View all your badges', 'agend-apps-core' ) );

	return agend_apps_records_member_summary_card( 'credentials', $title, $body, $footer );
}

/**
 * The directory listing tile.
 *
 * Drawn only for a member the profile says has a listing, or, when the editor
 * set a Manage listing link, as an invitation to use it. The profile carries no
 * listing both for a member with none and for a failed read, so the tile never
 * says "you are not listed".
 *
 * @param array $part  The directory part.
 * @param array $links Where the cards link to.
 * @return string '' when there is nothing to draw.
 */
function agend_apps_records_member_summary_render_directory( array $part, array $links ): string {
	$title  = __( 'Directory listing', 'agend-apps-core' );
	$manage = agend_apps_records_member_summary_more( $links['my_listing'], __( 'Manage your listing', 'agend-apps-core' ) );

	if ( 'unavailable' === $part['status'] ) {
		return agend_apps_records_member_summary_card( 'directory', $title, agend_apps_records_member_summary_tile_unavailable(), $manage );
	}

	if ( 'ready' !== $part['status'] ) {
		if ( '' === $links['my_listing'] ) {
			return '';
		}

		return agend_apps_records_member_summary_card( 'directory', $title, '<p class="agend-ms-quiet">' . esc_html__( 'Add or update your listing in the directory.', 'agend-apps-core' ) . '</p>', $manage );
	}

	$url = agend_apps_records_member_summary_listing_url( $part['slug'] );

	if ( '' !== $part['logo'] ) {
		$mark = '<img class="agend-ms-listing__logo" src="' . esc_url( $part['logo'] ) . '" alt="" width="52" height="52" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
	} else {
		$letter = function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $part['name'], 0, 1 ) ) : strtoupper( substr( $part['name'], 0, 1 ) );
		$mark   = '<span class="agend-ms-listing__logo agend-ms-listing__logo--letter" aria-hidden="true">' . esc_html( $letter ) . '</span>';
	}

	$body = '<div class="agend-ms-row agend-ms-listing' . ( '' !== $url ? ' agend-ms-row--link' : '' ) . '">'
		. $mark
		. '<span class="agend-ms-row__main">' . agend_apps_records_member_summary_row_title( $part['name'], $url )
		. '<ul class="agend-ms-meta" role="list"><li class="agend-ms-meta__item">' . esc_html( $part['verified'] ? __( 'Verified listing', 'agend-apps-core' ) : __( 'In the directory', 'agend-apps-core' ) ) . '</li></ul>'
		. '</span>'
		. ( '' !== $url ? agend_apps_records_member_summary_chevron() : '' )
		. '</div>';

	// The editor's manage link when there is one, else the listing itself.
	$footer = '' !== $manage ? $manage : agend_apps_records_member_summary_more( $url, __( 'View your listing', 'agend-apps-core' ) );

	return agend_apps_records_member_summary_card( 'directory', $title, $body, $footer );
}

/**
 * The reading lists tile: each list and how many pages are in it.
 *
 * @param array $part   The reading part.
 * @param array $config The render config.
 * @param array $links  Where the cards link to.
 * @return string
 */
function agend_apps_records_member_summary_render_reading( array $part, array $config, array $links ): string {
	$title  = __( 'Reading lists', 'agend-apps-core' );
	$footer = agend_apps_records_member_summary_more( $links['resources'], __( 'Browse resources', 'agend-apps-core' ) );

	if ( 'unavailable' === $part['status'] ) {
		return agend_apps_records_member_summary_card( 'reading', $title, agend_apps_records_member_summary_tile_unavailable(), $footer );
	}

	if ( 'empty' === $part['status'] ) {
		$body = '<p class="agend-ms-quiet">' . esc_html( '' !== $config['copy']['no_reading'] ? $config['copy']['no_reading'] : __( 'There are no reading lists for you yet.', 'agend-apps-core' ) ) . '</p>';

		return agend_apps_records_member_summary_card( 'reading', $title, $body, $footer );
	}

	$rows = '';

	foreach ( $part['items'] as $item ) {
		$rows .= '<li class="agend-ms-row agend-ms-row--plain"><span class="agend-ms-row__main"><span class="agend-ms-row__title">' . esc_html( $item['title'] ) . '</span></span>'
			/* translators: %s: number of pages in a reading list. */
			. '<span class="agend-ms-row__aside">' . esc_html( sprintf( _n( '%s page', '%s pages', (int) $item['pages'], 'agend-apps-core' ), number_format_i18n( (int) $item['pages'] ) ) ) . '</span></li>';
	}

	$more = '';

	if ( (int) $part['total'] > count( $part['items'] ) ) {
		/* translators: %s: number of further reading lists. */
		$more = '<p class="agend-ms-quiet">' . esc_html( sprintf( _n( 'and %s more.', 'and %s more.', (int) $part['total'] - count( $part['items'] ), 'agend-apps-core' ), number_format_i18n( (int) $part['total'] - count( $part['items'] ) ) ) ) . '</p>';
	}

	return agend_apps_records_member_summary_card( 'reading', $title, '<ul class="agend-ms-list" role="list">' . $rows . '</ul>' . $more, $footer );
}

// ---------------------------------------------------------------------------
// States
// ---------------------------------------------------------------------------

/**
 * A notice card: what is going on, and what the member can do about it.
 *
 * Words come from the access state's member-safe message, never from the
 * reason. An administrator also gets the reason, in a note that says only
 * administrators see it.
 *
 * @param array $config The render config.
 * @param array $access The access state.
 * @param array $opts   `settled` (bool): the member has waited as long as the
 *                      page will wait for a connection.
 * @return string
 */
function agend_apps_records_member_summary_render_notice( array $config, array $access, array $opts = array() ): string {
	$state   = (string) $access['state'];
	$action  = (string) $access['action'];
	$message = agend_apps_member_access_message( $access );
	$links   = agend_apps_records_member_summary_links( $config );
	$title   = $message['title'];
	$text    = $message['message'];
	$buttons = '';

	// The editor's own words for the two states they can reword.
	if ( 'no_member_record' === $state && '' !== $config['copy']['no_record'] ) {
		$text = $config['copy']['no_record'];
	}

	if ( in_array( $state, array( 'unavailable', 'not_configured' ), true ) && 'contact' !== $action && '' !== $config['copy']['unavailable'] && 'busy' !== $access['reason'] ) {
		$text = $config['copy']['unavailable'];
	}

	if ( 'busy' === $access['reason'] && (int) $access['retry_after'] > 0 ) {
		/* translators: %s: number of seconds. */
		$text = sprintf( _n( 'Please try again in %s second.', 'Please try again in %s seconds.', (int) $access['retry_after'], 'agend-apps-core' ), number_format_i18n( (int) $access['retry_after'] ) );
	}

	if ( 'connecting' === $state && ! empty( $opts['settled'] ) ) {
		$title = __( 'Still connecting', 'agend-apps-core' );
		$text  = __( 'This is taking longer than usual. Try again in a few minutes.', 'agend-apps-core' );
	}

	switch ( $action ) {
		case 'sign_in':
			$buttons .= agend_apps_records_member_summary_button( (string) $access['sign_in_url'], $config['copy']['sign_in'] );
			break;

		case 'sign_in_again':
			$buttons .= agend_apps_records_member_summary_button( wp_logout_url( (string) $access['sign_in_url'] ), __( 'Sign out and sign in again', 'agend-apps-core' ) );
			break;

		case 'connect':
			$buttons .= agend_apps_records_member_summary_button( (string) $access['initiate_url'], __( 'Connect my account', 'agend-apps-core' ) );
			break;

		case 'join':
			if ( '' !== $links['join'] ) {
				$buttons .= agend_apps_records_member_summary_button( $links['join'], __( 'Join', 'agend-apps-core' ) );
			}
			break;

		case 'retry':
			// A connection still being made retries by itself; the button is for
			// when it has given up, and for everything else that can be retried.
			if ( 'connecting' !== $state || ! empty( $opts['settled'] ) ) {
				$buttons .= agend_apps_records_member_summary_retry_button( __( 'Try again', 'agend-apps-core' ) );
			}
			break;
	}

	// Somewhere to turn when the member cannot fix it themselves.
	if ( in_array( $action, array( 'join', 'contact' ), true ) && '' !== $links['support'] ) {
		$buttons .= agend_apps_records_member_summary_button( $links['support'], __( 'Contact us', 'agend-apps-core' ), '' !== $buttons ? 'quiet' : '' );
	}

	$admin = '';

	if ( '' !== (string) $access['detail'] && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
		$admin = '<p class="agend-ms-admin"><strong>' . esc_html__( 'Only administrators see this note.', 'agend-apps-core' ) . '</strong> ' . esc_html( (string) $access['detail'] ) . '</p>';
	}

	return '<div class="agend-ms-notice agend-ms-notice--' . esc_attr( str_replace( '_', '-', $state ) ) . '" data-agend-ms-state="' . esc_attr( $state ) . '">'
		. '<span class="agend-ms-notice__mark" aria-hidden="true"></span>'
		. '<div class="agend-ms-notice__text">'
		. '<h3 class="agend-ms-notice__title">' . esc_html( $title ) . '</h3>'
		. '<p class="agend-ms-notice__message">' . esc_html( $text ) . '</p>'
		. $admin
		. ( '' !== $buttons ? '<p class="agend-ms-actions">' . $buttons . '</p>' : '' )
		. '</div></div>';
}

/**
 * What a visitor who is not signed in sees: an invitation.
 *
 * It is the welcome band a member gets, in a plain state: the person's outline in
 * place of initials, what signing in gives them, and one button. Under it are the
 * names of the tiles the editor has switched on, so a visitor sees what they will
 * find. Nothing about any member, and nothing made up, is drawn.
 *
 * @param array  $config  The render config.
 * @param string $sign_in Where signing in leads, back to this page.
 * @return string
 */
function agend_apps_records_member_summary_render_signed_out( array $config, string $sign_in ): string {
	$names = agend_apps_records_member_summary_part_names();
	$chips = '';

	foreach ( $names as $key => $name ) {
		if ( ! empty( $config['sections'][ $key ] ) ) {
			$chips .= '<li class="agend-ms-pill agend-ms-pill--soft"><span class="agend-ms-pill__icon">' . agend_apps_records_member_summary_icon( $key, '16' ) . '</span>' . esc_html( $name ) . '</li>';
		}
	}

	return '<div class="agend-ms-hero agend-ms-hero--invite agend-ms-notice--signed-out" data-agend-ms-state="signed_out">'
		. agend_apps_records_member_summary_avatar( '' )
		. '<div class="agend-ms-hero__main">'
		. '<h3 class="agend-ms-hero__title">' . esc_html__( 'Sign in to see your membership', 'agend-apps-core' ) . '</h3>'
		. ( '' !== $config['copy']['signed_out'] ? '<p class="agend-ms-hero__role">' . esc_html( $config['copy']['signed_out'] ) . '</p>' : '' )
		. ( '' !== $chips ? '<ul class="agend-ms-pills agend-ms-pills--soft" role="list" aria-label="' . esc_attr__( 'What you will find here', 'agend-apps-core' ) . '">' . $chips . '</ul>' : '' )
		. '</div>'
		. '<div class="agend-ms-hero__side"><p class="agend-ms-hero__actions">' . agend_apps_records_member_summary_button( $sign_in, '' !== $config['copy']['sign_in'] ? $config['copy']['sign_in'] : __( 'Sign in', 'agend-apps-core' ), 'on-band' ) . '</p></div>'
		. '</div>';
}

/**
 * The view for a member whose access is ready, or the notice for one whose is
 * not. This is the fragment the route sends.
 *
 * The welcome band first, then the tiles in one even grid. Parts the key does
 * not allow are left out; a part that failed says so in its own tile, and one
 * "Try again" serves them all. An administrator is told which parts were left
 * out and why, in a note members do not get.
 *
 * @param array $config The render config.
 * @param array $result The summary: `access`, `contact`, `sections`
 *                      ({@see agend_apps_member_summary_fetch()}).
 * @param array $opts   `settled` (bool), passed to the notice.
 * @return string
 */
function agend_apps_records_member_summary_render_body( array $config, array $result, array $opts = array() ): string {
	if ( 'ready' !== $result['access']['state'] ) {
		return agend_apps_records_member_summary_render_notice( $config, $result['access'], $opts );
	}

	$links    = agend_apps_records_member_summary_links( $config );
	$sections = $result['sections'];
	$contact  = (array) ( $result['contact'] ?? array() );
	$out      = '';
	$grid     = '';
	$failed   = false;
	$hidden   = array();

	foreach ( $sections as $name => $part ) {
		if ( 'no_access' === ( $part['status'] ?? '' ) ) {
			$hidden[] = $name;
		}

		if ( 'unavailable' === ( $part['status'] ?? '' ) ) {
			$failed = true;
		}
	}

	$hero = agend_apps_records_member_summary_render_hero( $config, $contact, (array) ( $sections['membership'] ?? array() ), $links );
	$out .= $hero;

	// One loop, in the order the parts are drawn, so a part is never drawn twice
	// or out of place.
	foreach ( agend_apps_member_summary_sections() as $name ) {
		if ( ! isset( $sections[ $name ] ) || 'no_access' === $sections[ $name ]['status'] ) {
			continue;
		}

		switch ( $name ) {
			case 'cpd':
				$grid .= agend_apps_records_member_summary_render_cpd( $sections[ $name ], $links );
				break;
			case 'events':
				$grid .= agend_apps_records_member_summary_render_events( $sections[ $name ], $config, $links );
				break;
			case 'courses':
				$grid .= agend_apps_records_member_summary_render_courses( $sections[ $name ], $config, $links );
				break;
			case 'credentials':
				$grid .= agend_apps_records_member_summary_render_credentials( $sections[ $name ], $config, $links );
				break;
			case 'directory':
				$grid .= agend_apps_records_member_summary_render_directory( $sections[ $name ], $links );
				break;
			case 'reading':
				$grid .= agend_apps_records_member_summary_render_reading( $sections[ $name ], $config, $links );
				break;
		}
	}

	if ( '' !== $grid ) {
		$out .= '<div class="agend-ms-grid">' . $grid . '</div>';
	}

	if ( $failed ) {
		$out .= '<p class="agend-ms-actions agend-ms-actions--retry">' . agend_apps_records_member_summary_retry_button( __( 'Try again', 'agend-apps-core' ) ) . '</p>';
	}

	if ( array() !== $hidden && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
		$names = agend_apps_records_member_summary_part_names();

		$out .= '<p class="agend-ms-admin"><strong>' . esc_html__( 'Only administrators see this note.', 'agend-apps-core' ) . '</strong> '
			. esc_html(
				sprintf(
					/* translators: %s: names of the parts left out. */
					__( 'Left out for members because the connected key lacks the permissions they need: %s. Add them to the key in the Agend dashboard.', 'agend-apps-core' ),
					implode( ', ', array_map( static fn( string $name ): string => $names[ $name ] ?? $name, $hidden ) )
				)
			)
			. '</p>';
	}

	if ( '' === $grid && '' === $hero ) {
		$out .= '<p class="agend-ms-quiet">' . esc_html__( 'There is nothing to show here yet.', 'agend-apps-core' ) . '</p>';
	}

	return $out;
}

// ---------------------------------------------------------------------------
// The editor's example
// ---------------------------------------------------------------------------

/**
 * A summary of an imaginary member, for the editor's labelled example. Never
 * drawn on the front end.
 *
 * Dates are measured from now so the example never looks stale.
 *
 * @param int $now The time (a test seam).
 * @return array The shape of {@see agend_apps_member_summary_fetch()}.
 */
function agend_apps_records_member_summary_example( int $now = 0 ): array {
	$now = $now > 0 ? $now : time();
	$day = 86400;

	return array(
		'access'   => array(
			'state'        => 'ready',
			'reason'       => '',
			'mode'         => 'credentials',
			'signed_in'    => true,
			'linked'       => true,
			'action'       => 'none',
			'retry_after'  => 0,
			'sign_in_url'  => '',
			'initiate_url' => '',
			'detail'       => '',
		),
		'contact'  => array(
			'first_name' => 'Priya',
			'initials'   => 'PR',
			'title'      => __( 'Practice lead', 'agend-apps-core' ),
			'company'    => __( 'Example Studio', 'agend-apps-core' ),
		),
		'sections' => array(
			'membership'  => array(
				'status'   => 'ready',
				'label'    => 'active',
				'tier'     => __( 'Professional', 'agend-apps-core' ),
				'via_seat' => false,
				'date'     => gmdate( 'Y-m-d\T00:00:00\Z', $now + 112 * $day ),
				'kind'     => 'renews',
				'days'     => 112,
			),
			'cpd'         => array(
				'status'     => 'ready',
				'form'       => 'target',
				'earned'     => 14.5,
				'target'     => 20.0,
				'pace'       => 'on_track',
				'days_left'  => 224,
				'period_end' => gmdate( 'Y-m-d', $now + 224 * $day ),
				'adjustment' => '',
				'completed'  => 3,
			),
			'events'      => array(
				'status' => 'ready',
				'items'  => array(
					array(
						'title'  => __( 'Annual conference', 'agend-apps-core' ),
						'slug'   => '',
						'date'   => gmdate( 'Y-m-d\T01:00:00\Z', $now + 21 * $day ),
						'status' => 'registered',
					),
					array(
						'title'  => __( 'Pricing your work, a webinar', 'agend-apps-core' ),
						'slug'   => '',
						'date'   => gmdate( 'Y-m-d\T03:30:00\Z', $now + 40 * $day ),
						'status' => 'registered',
					),
				),
				'total'  => 2,
			),
			'courses'     => array(
				'status' => 'ready',
				'items'  => array(
					array(
						'title'   => __( 'Pricing your work', 'agend-apps-core' ),
						'slug'    => '',
						'percent' => 72.0,
						'done'    => 6,
						'lessons' => 8,
					),
					array(
						'title'   => __( 'Contracts for studios', 'agend-apps-core' ),
						'slug'    => '',
						'percent' => 35.0,
						'done'    => 3,
						'lessons' => 9,
					),
				),
				'total'  => 2,
			),
			'credentials' => array(
				'status'             => 'ready',
				'certificates'       => array(
					array(
						'title'  => __( 'Briefing a client well', 'agend-apps-core' ),
						'code'   => '',
						'issued' => gmdate( 'Y-m-d\T00:00:00\Z', $now - 48 * $day ),
						'cpd'    => 4.0,
					),
					array(
						'title'  => __( 'Accessible design foundations', 'agend-apps-core' ),
						'code'   => '',
						'issued' => gmdate( 'Y-m-d\T00:00:00\Z', $now - 190 * $day ),
						'cpd'    => 6.5,
					),
				),
				'certificates_total' => 2,
				'badges'             => array(
					array(
						'name'   => __( 'Client briefing', 'agend-apps-core' ),
						'colour' => '',
						'course' => '',
					),
					array(
						'name'   => __( 'Accessible design', 'agend-apps-core' ),
						'colour' => '',
						'course' => '',
					),
				),
				'badges_total'       => 2,
			),
			'directory'   => array(
				'status'   => 'ready',
				'name'     => __( 'Example Studio', 'agend-apps-core' ),
				'slug'     => '',
				'verified' => true,
				'logo'     => '',
			),
			'reading'     => array(
				'status' => 'ready',
				'items'  => array(
					array(
						'title' => __( 'New member starter pack', 'agend-apps-core' ),
						'pages' => 5,
					),
					array(
						'title' => __( 'Running a studio', 'agend-apps-core' ),
						'pages' => 6,
					),
				),
				'total'  => 2,
			),
		),
		'reads'    => array(),
	);
}

/**
 * The example for the editor: the cards of an imaginary member, drawn from the
 * same markup a member sees, under a label that says what it is. Only the parts
 * the editor has switched on are drawn, so the example follows their choices.
 *
 * @param array $config The render config.
 * @return string
 */
function agend_apps_records_member_summary_render_example( array $config ): string {
	$example = agend_apps_records_member_summary_example();

	foreach ( array_keys( $example['sections'] ) as $name ) {
		if ( empty( $config['sections'][ $name ] ) ) {
			unset( $example['sections'][ $name ] );
		}
	}

	return '<p class="agend-ms-example-label">' . esc_html__( 'Example for editing only. Members see their own details here.', 'agend-apps-core' ) . '</p>'
		. agend_apps_records_member_summary_render_body( $config, $example );
}

// ---------------------------------------------------------------------------
// Rendering the surface
// ---------------------------------------------------------------------------

/**
 * The parts of the shell every state shares: the heading.
 *
 * @param array $config The render config.
 * @return string
 */
function agend_apps_records_member_summary_render_heading( array $config ): string {
	if ( empty( $config['heading']['show'] ) || '' === $config['heading']['title'] ) {
		return '';
	}

	return '<div class="agend-member-summary__heading"><h2 class="agend-member-summary__title">' . esc_html( $config['heading']['title'] ) . '</h2></div>';
}

/**
 * The page's own address, for signing in and coming back to it.
 *
 * @return string
 */
function agend_apps_records_member_summary_current_url(): string {
	return home_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passed to home_url() and escaped on output.
}

/**
 * Renders the surface on the front end, returned as HTML.
 *
 * @param array $settings Surface settings (Elementor-shaped values).
 * @param array $opts     `preview` (bool): drawn in an editor, where it shows
 *                        the labelled example and makes no call.
 * @return string
 */
function agend_apps_records_render_member_summary( array $settings, array $opts = array() ): string {
	$preview = ! empty( $opts['preview'] );
	$gate    = agend_apps_records_surface_gate( 'member-summary', $preview );

	if ( null !== $gate ) {
		return $gate;
	}

	// A member's own cards inside a card template would be fetched once per
	// card; nothing sensible can come of it.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config  = agend_apps_records_member_summary_build_config( $settings );
	$style   = agend_apps_records_member_summary_inline_style( $config );
	$heading = agend_apps_records_member_summary_render_heading( $config );

	if ( $preview ) {
		return '<div class="agend-member-summary agend-member-summary--example" style="' . esc_attr( $style ) . '">' . $heading
			. '<div class="agend-member-summary__body">' . agend_apps_records_member_summary_render_example( $config ) . '</div></div>';
	}

	// Signed out: a card the server draws, with no script and no call.
	if ( ! is_user_logged_in() ) {
		if ( $config['hideWhenSignedOut'] ) {
			return '';
		}

		return '<div class="agend-member-summary agend-member-summary--signed-out" style="' . esc_attr( $style ) . '">' . $heading
			. '<div class="agend-member-summary__body">' . agend_apps_records_member_summary_render_signed_out( $config, wp_login_url( agend_apps_records_member_summary_current_url() ) ) . '</div></div>';
	}

	// Signed in: a shell with nothing about the member in it. The same markup
	// for every visitor, so a page cache may keep it.
	$client = array(
		'route'    => rest_url( 'agend-apps/v1/member/summary' ),
		'settings' => agend_apps_records_member_summary_client_settings( $settings ),
		'messages' => array(
			'unavailableTitle' => __( 'We cannot load your membership right now', 'agend-apps-core' ),
			'unavailable'      => __( 'Please try again in a moment.', 'agend-apps-core' ),
			'reload'           => __( 'This page has been open a while. Reload it to see your membership.', 'agend-apps-core' ),
			'retry'            => __( 'Try again', 'agend-apps-core' ),
			'reloadLabel'      => __( 'Reload the page', 'agend-apps-core' ),
		),
	);

	return '<div class="agend-member-summary" style="' . esc_attr( $style ) . '" data-agend-member-summary-config="' . esc_attr( (string) wp_json_encode( $client ) ) . '">' . $heading
		. '<div class="agend-member-summary__body" aria-live="polite" aria-busy="true" data-agend-ms-body>'
		. '<div class="agend-ms-loading" role="status"><span class="agend-ms-sr">' . esc_html__( 'Loading your membership', 'agend-apps-core' ) . '</span>'
		. '<span class="agend-ms-loading__band" aria-hidden="true"></span><span class="agend-ms-grid" aria-hidden="true"><span class="agend-ms-loading__card agend-ms-card"></span><span class="agend-ms-loading__card agend-ms-card"></span><span class="agend-ms-loading__card agend-ms-card"></span></span></div>'
		. '</div>'
		. '<noscript><p class="agend-ms-quiet">' . esc_html__( 'This page needs JavaScript to show your membership.', 'agend-apps-core' ) . '</p></noscript>'
		. '</div>';
}

/**
 * Editor notices for the surface, shown above the block's placeholder: how
 * members sign in decides what they will see, and an administrator needs to
 * know what a key without a permission does to the cards.
 *
 * @param array  $notices The notices so far.
 * @param string $surface The surface id.
 * @return array
 */
function agend_apps_records_member_summary_block_notices( array $notices, string $surface ): array {
	if ( 'member-summary' !== $surface ) {
		return $notices;
	}

	$notices[] = array(
		'status' => 'info',
		'text'   => __( 'Members see their own details here once they sign in and are connected to their membership. Visitors who are not signed in see a sign-in card. The cards below are an example.', 'agend-apps-core' ),
	);

	$left_out = array();
	$names    = agend_apps_records_member_summary_part_names();

	// The reading lists are off until an editor turns them on, so a key that
	// lacks them is not worth a warning about a part no one asked for.
	$checked = array_values( array_diff( agend_apps_member_summary_sections(), array( 'reading' ) ) );

	foreach ( agend_apps_member_summary_plan( array( 'sections' => $checked ) )['no_access'] as $name ) {
		$left_out[] = $names[ $name ] ?? $name;
	}

	if ( array() !== $left_out ) {
		$notices[] = array(
			'status' => 'warning',
			'text'   => sprintf(
				/* translators: %s: names of the parts left out. */
				__( 'The connected key does not allow these parts, so members will not see them: %s. Add the permissions to the key in the Agend dashboard.', 'agend-apps-core' ),
				implode( ', ', $left_out )
			),
		);
	}

	return $notices;
}
add_filter( 'agend_apps_records_block_surface_notices', 'agend_apps_records_member_summary_block_notices', 10, 2 );
