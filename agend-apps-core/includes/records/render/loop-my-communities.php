<?php
/**
 * Server render of the My Communities surface: the communities (Agend Loop
 * channels) a signed-in member belongs to, each with an "Access community"
 * button that signs them in to Loop and opens the channel.
 *
 * Page-builder agnostic: an adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema( 'loop-my-communities' )`) and echoes the
 * returned HTML.
 *
 * Personal content is never in the page. The page carries a neutral shell (the
 * heading and a loading state), the same for every visitor and so safe in any
 * page cache, and a script that asks `agend-apps/v1/member/communities` for the
 * member's own list. That answer is `no-store` and varies by cookie. The list
 * is drawn here, in PHP, by {@see agend_apps_records_my_communities_render_body()}
 * and sent as a fragment, so there is one set of markup, escaped in one place.
 *
 * A visitor who is not signed in gets a sign-in card, drawn on the server so it
 * needs no script. Nothing sample-shaped is drawn on the front end: the labelled
 * example is for the editor, behind the `preview` option.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../schema/loop-my-communities.php';
require_once __DIR__ . '/../palette.php';
require_once __DIR__ . '/../format.php';
require_once __DIR__ . '/../../member-access-state.php';
require_once __DIR__ . '/../../loop-my-communities.php';

/**
 * The settings that decide what the list says. These are the ones the browser
 * hands back with each fetch, so the fragment can be drawn for this instance.
 * Colours and layout stay on the page.
 *
 * @return string[]
 */
function agend_apps_records_my_communities_content_keys(): array {
	return array(
		'show_description',
		'button_label',
		'empty_message',
		'unavailable_message',
		'signed_out_message',
		'sign_in_label',
		'hide_when_signed_out',
	);
}

/**
 * The content settings as plain values, for the page to hold and the browser to
 * send back: only the known keys, scalars only.
 *
 * @param array $settings Surface settings.
 * @return array<string, mixed>
 */
function agend_apps_records_my_communities_client_settings( array $settings ): array {
	$out = array();

	foreach ( agend_apps_records_my_communities_content_keys() as $key ) {
		if ( array_key_exists( $key, $settings ) ) {
			$value       = $settings[ $key ];
			$out[ $key ] = is_scalar( $value ) || null === $value ? $value : '';
		}
	}

	return $out;
}

/**
 * Builds what the list needs from the content settings, typed and bounded.
 *
 * Also what the route runs on whatever the browser sends back, so it is the one
 * place that decides what a value may be.
 *
 * @param array $s Settings (Elementor-shaped: toggles are 'yes'/'').
 * @return array
 */
function agend_apps_records_my_communities_content_config( array $s ): array {
	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		$value = $s[ $key ] ?? $fallback;

		return 'yes' === $value || true === $value || '1' === $value || 1 === $value;
	};

	$text = static function ( string $key, string $fallback ) use ( $s ): string {
		$value = array_key_exists( $key, $s ) ? trim( wp_strip_all_tags( is_scalar( $s[ $key ] ) ? (string) $s[ $key ] : '' ) ) : '';

		return '' !== $value ? $value : $fallback;
	};

	return array(
		'description'       => $on( 'show_description' ),
		'copy'              => array(
			'button'      => $text( 'button_label', __( 'Access community', 'agend-apps-core' ) ),
			'empty'       => $text( 'empty_message', __( 'You are not in any communities yet.', 'agend-apps-core' ) ),
			'unavailable' => $text( 'unavailable_message', __( 'Your communities are not available right now. Please try again later.', 'agend-apps-core' ) ),
			'signed_out'  => $text( 'signed_out_message', __( 'Sign in to see the communities you belong to.', 'agend-apps-core' ) ),
			'sign_in'     => $text( 'sign_in_label', __( 'Sign in', 'agend-apps-core' ) ),
		),
		'hideWhenSignedOut' => $on( 'hide_when_signed_out', '' ),
	);
}

/**
 * Builds the full render config: the content config, the heading, the colours
 * and the card corner.
 *
 * @param array $s Surface settings.
 * @return array
 */
function agend_apps_records_my_communities_build_config( array $s ): array {
	$colours = agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $s['inherit_colours'] ?? 'yes' ) ) + $s,
		array(
			'heading' => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['heading'],
			'body'    => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'],
			'accent'  => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['accent'],
		)
	);

	return agend_apps_records_my_communities_content_config( $s ) + array(
		'heading'    => array(
			'show'  => 'yes' === ( $s['show_heading'] ?? 'yes' ),
			'title' => array_key_exists( 'heading_text', $s ) ? trim( (string) $s['heading_text'] ) : __( 'My communities', 'agend-apps-core' ),
		),
		'cardRadius' => max( 0, min( 48, (int) ( $s['card_radius'] ?? 10 ) ) ),
		'colours'    => $colours['colours'],
	);
}

/**
 * The colour and corner CSS variables the stylesheet reads.
 *
 * @param array $config The render config.
 * @return string
 */
function agend_apps_records_my_communities_inline_style( array $config ): string {
	return sprintf(
		'--agend-mc-heading:%1$s;--agend-mc-body:%2$s;--agend-mc-accent:%3$s;--agend-mc-card-radius:%4$dpx;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] ),
		(int) $config['cardRadius']
	);
}

/**
 * The heading every state shares.
 *
 * @param array $config The render config.
 * @return string
 */
function agend_apps_records_my_communities_render_heading( array $config ): string {
	if ( empty( $config['heading']['show'] ) || '' === $config['heading']['title'] ) {
		return '';
	}

	return '<div class="agend-my-communities__heading"><h2 class="agend-my-communities__title">' . esc_html( $config['heading']['title'] ) . '</h2></div>';
}

/**
 * A link-styled button.
 *
 * @param string $href    Address.
 * @param string $label   Text.
 * @param bool   $new_tab Open in a new tab.
 * @return string
 */
function agend_apps_records_my_communities_button( string $href, string $label, bool $new_tab = false ): string {
	return '<a class="agend-mc-button exclude-agend-theme-link" href="' . esc_url( $href ) . '"'
		. ( $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>'
		. esc_html( $label )
		. ( $new_tab ? '<span class="agend-mc-sr"> ' . esc_html__( '(opens in a new tab)', 'agend-apps-core' ) . '</span>' : '' )
		. '</a>';
}

/**
 * A notice: a title, a line of text and an optional administrator note.
 *
 * @param string $state Machine state, for styling and tests.
 * @param string $title Title.
 * @param string $text  Message.
 * @param string $admin Note only an administrator sees, or ''.
 * @return string
 */
function agend_apps_records_my_communities_notice( string $state, string $title, string $text, string $admin = '' ): string {
	return '<div class="agend-mc-notice agend-mc-notice--' . esc_attr( str_replace( '_', '-', $state ) ) . '" data-agend-mc-state="' . esc_attr( $state ) . '">'
		. ( '' !== $title ? '<h3 class="agend-mc-notice__title">' . esc_html( $title ) . '</h3>' : '' )
		. '<p class="agend-mc-notice__message">' . esc_html( $text ) . '</p>'
		. ( '' !== $admin ? '<p class="agend-mc-admin"><strong>' . esc_html__( 'Only administrators see this note.', 'agend-apps-core' ) . '</strong> ' . esc_html( $admin ) . '</p>' : '' )
		. '</div>';
}

/**
 * Whether the viewer is an administrator, who gets set-up notes members do not.
 *
 * @return bool
 */
function agend_apps_records_my_communities_viewer_is_admin(): bool {
	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

/**
 * What a visitor who is not signed in sees: a card with a sign-in button.
 *
 * @param array  $config  The render config.
 * @param string $sign_in Where signing in leads, back to this page.
 * @return string
 */
function agend_apps_records_my_communities_render_signed_out( array $config, string $sign_in ): string {
	return '<div class="agend-mc-notice agend-mc-notice--signed-out" data-agend-mc-state="signed_out">'
		. '<p class="agend-mc-notice__message">' . esc_html( $config['copy']['signed_out'] ) . '</p>'
		. '<p class="agend-mc-actions">' . agend_apps_records_my_communities_button( $sign_in, $config['copy']['sign_in'] ) . '</p>'
		. '</div>';
}

/**
 * The fragment for a member: the notice for a state that has no list, or the
 * list of communities. This is what the route sends.
 *
 * @param array $config The render config.
 * @param array $result {@see Agend_Apps_Loop_My_Communities::for_current_user()}.
 * @param bool  $admin  Whether to include the administrator notes.
 * @return string
 */
function agend_apps_records_my_communities_render_body( array $config, array $result, bool $admin = false ): string {
	$state = (string) $result['state'];

	switch ( $state ) {
		case Agend_Apps_Loop_My_Communities::STATE_NO_CONTACT:
			return agend_apps_records_my_communities_notice(
				$state,
				'',
				__( 'We could not match your account to a membership, so your communities cannot be shown.', 'agend-apps-core' ),
				$admin ? __( 'This account has no membership number (external id), so Agend cannot be asked which channels it is in.', 'agend-apps-core' ) : ''
			);

		case Agend_Apps_Loop_My_Communities::STATE_REFUSED:
			return agend_apps_records_my_communities_notice(
				$state,
				'',
				$config['copy']['unavailable'],
				$admin ? __( 'The connected Agend key cannot read channel memberships. It needs the loop.memberships.read permission, access to Loop, and this site\'s contact source in its allowed sources.', 'agend-apps-core' ) : ''
			);

		case Agend_Apps_Loop_My_Communities::STATE_OK:
			break;

		default:
			return agend_apps_records_my_communities_notice(
				Agend_Apps_Loop_My_Communities::STATE_UNAVAILABLE,
				'',
				$config['copy']['unavailable'],
				$admin ? __( 'Agend did not return the member\'s channel list. Either Agend could not be reached just now, or it does not yet support the include=channels option on the channel membership route.', 'agend-apps-core' ) : ''
			);
	}

	if ( array() === $result['communities'] ) {
		return agend_apps_records_my_communities_notice( 'empty', '', $config['copy']['empty'] );
	}

	$note  = $admin && empty( $result['links'] )
		? agend_apps_records_my_communities_notice( 'not-configured', '', __( 'Members can see their communities, but there is no button to open them yet.', 'agend-apps-core' ), __( 'Set the Agend account slug in Settings > Identity and SSO so each community can link to Loop.', 'agend-apps-core' ) )
		: '';
	$cards = '';

	foreach ( $result['communities'] as $community ) {
		$cards .= '<li class="agend-mc-card" data-agend-mc-type="' . esc_attr( (string) $community['channel_type'] ) . '">'
			. '<h3 class="agend-mc-card__title">' . esc_html( (string) $community['name'] ) . '</h3>'
			. ( $config['description'] && '' !== $community['description'] ? '<p class="agend-mc-card__description">' . esc_html( (string) $community['description'] ) . '</p>' : '' )
			. ( '' !== $community['access_url'] ? '<p class="agend-mc-actions">' . agend_apps_records_my_communities_button( (string) $community['access_url'], $config['copy']['button'], true ) . '</p>' : '' )
			. '</li>';
	}

	return $note . '<ul class="agend-mc-list" role="list" data-agend-mc-state="ok">' . $cards . '</ul>';
}

/**
 * The example for the editor: a made-up list under a label that says what it
 * is. Its buttons go nowhere.
 *
 * @param array $config The render config.
 * @return string
 */
function agend_apps_records_my_communities_render_example( array $config ): string {
	$example = array(
		'state'       => Agend_Apps_Loop_My_Communities::STATE_OK,
		'links'       => true,
		'communities' => array(
			array(
				'id'           => 'example-1',
				'name'         => __( 'Fellows', 'agend-apps-core' ),
				'description'  => __( 'A place for Fellows to share ideas and ask questions.', 'agend-apps-core' ),
				'channel_type' => 'channel',
				'access_url'   => '#',
			),
			array(
				'id'           => 'example-2',
				'name'         => __( 'Technical committee', 'agend-apps-core' ),
				'description'  => __( 'Papers, agendas and discussion for committee members.', 'agend-apps-core' ),
				'channel_type' => 'committee',
				'access_url'   => '#',
			),
		),
	);

	return '<p class="agend-mc-example-label">' . esc_html__( 'Example for editing only. Members see the communities they belong to here.', 'agend-apps-core' ) . '</p>'
		. agend_apps_records_my_communities_render_body( $config, $example );
}

/**
 * The page's own address, for signing in and coming back to it.
 *
 * `REQUEST_URI` already carries any subdirectory the site lives in, so it is
 * appended to the site's origin (read from `home_url()`, never the request's
 * `Host` header) rather than passed to `home_url()`, which would repeat it.
 *
 * @return string
 */
function agend_apps_records_my_communities_current_url(): string {
	$parts = wp_parse_url( home_url( '/' ) );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return home_url( '/' );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A server-set path and query, only used as a sign-in return address and escaped on output.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

	if ( '' === $uri || '/' !== $uri[0] ) {
		$uri = '/' . $uri;
	}

	$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	return esc_url_raw( $origin . $uri );
}

/**
 * Renders the surface on the front end, returned as HTML.
 *
 * @param array $settings Surface settings (Elementor-shaped values).
 * @param array $opts     `preview` (bool): drawn in an editor, where it shows
 *                        the labelled example and makes no call.
 * @return string
 */
function agend_apps_records_render_loop_my_communities( array $settings, array $opts = array() ): string {
	$preview = ! empty( $opts['preview'] );
	$gate    = agend_apps_records_surface_gate( 'loop-my-communities', $preview );

	if ( null !== $gate ) {
		return $gate;
	}

	// A member's own list inside a card template would be fetched once per card.
	if ( class_exists( 'Agend_Apps_Records_Record_Context' ) && Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config  = agend_apps_records_my_communities_build_config( $settings );
	$style   = agend_apps_records_my_communities_inline_style( $config );
	$heading = agend_apps_records_my_communities_render_heading( $config );

	if ( $preview ) {
		return '<div class="agend-my-communities agend-my-communities--example" style="' . esc_attr( $style ) . '">' . $heading
			. '<div class="agend-my-communities__body">' . agend_apps_records_my_communities_render_example( $config ) . '</div></div>';
	}

	// Signed out: a card the server draws, with no script and no call.
	if ( ! is_user_logged_in() ) {
		if ( $config['hideWhenSignedOut'] ) {
			return '';
		}

		$access = agend_apps_member_access_state( array( 'return_url' => agend_apps_records_my_communities_current_url() ) );

		return '<div class="agend-my-communities agend-my-communities--signed-out" style="' . esc_attr( $style ) . '">' . $heading
			. '<div class="agend-my-communities__body">' . agend_apps_records_my_communities_render_signed_out( $config, (string) $access['sign_in_url'] ) . '</div></div>';
	}

	// Signed in: a shell with nothing about the member in it. The same markup
	// for every visitor, so a page cache may keep it.
	$client = array(
		'route'    => rest_url( 'agend-apps/v1/member/communities' ),
		'settings' => agend_apps_records_my_communities_client_settings( $settings ),
		'messages' => array(
			'unavailableTitle' => __( 'We cannot load your communities right now', 'agend-apps-core' ),
			'unavailable'      => __( 'Please try again in a moment.', 'agend-apps-core' ),
			'reload'           => __( 'This page has been open a while. Reload it to see your communities.', 'agend-apps-core' ),
			'retry'            => __( 'Try again', 'agend-apps-core' ),
			'reloadLabel'      => __( 'Reload the page', 'agend-apps-core' ),
		),
	);

	return '<div class="agend-my-communities" style="' . esc_attr( $style ) . '" data-agend-my-communities-config="' . esc_attr( (string) wp_json_encode( $client ) ) . '">' . $heading
		. '<div class="agend-my-communities__body" aria-live="polite" aria-busy="true" data-agend-mc-body>'
		. '<div class="agend-mc-loading" role="status"><span class="agend-mc-sr">' . esc_html__( 'Loading your communities', 'agend-apps-core' ) . '</span>'
		. '<span class="agend-mc-loading__card agend-mc-card" aria-hidden="true"></span><span class="agend-mc-loading__card agend-mc-card" aria-hidden="true"></span></div>'
		. '</div>'
		. '<noscript><p class="agend-mc-quiet">' . esc_html__( 'This page needs JavaScript to show your communities.', 'agend-apps-core' ) . '</p></noscript>'
		. '</div>';
}

/**
 * Editor notice for the surface: what members see, and what it needs.
 *
 * @param array  $notices The notices so far.
 * @param string $surface The surface id.
 * @return array
 */
function agend_apps_records_my_communities_block_notices( array $notices, string $surface ): array {
	if ( 'loop-my-communities' !== $surface ) {
		return $notices;
	}

	$notices[] = array(
		'status' => 'info',
		'text'   => __( 'Members see the communities they belong to here once they sign in. Visitors who are not signed in see a sign-in card. The list below is an example.', 'agend-apps-core' ),
	);

	if ( ! Agend_Apps_Loop_My_Communities::links_configured() ) {
		$notices[] = array(
			'status' => 'warning',
			'text'   => __( 'The Agend account slug is not set, so communities show without an Access community button. Set it under Settings > Identity and SSO.', 'agend-apps-core' ),
		);
	}

	return $notices;
}
add_filter( 'agend_apps_records_block_surface_notices', 'agend_apps_records_my_communities_block_notices', 10, 2 );
