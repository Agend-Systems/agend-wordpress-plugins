<?php
/**
 * Server render of the listing enquiry form: a form on a directory listing's
 * page that sends the visitor's message to the listed member through the
 * Agend gateway.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * An adapter passes the settings and echoes the returned HTML. WordPress sends
 * no email; the browser posts to the `agend-apps/v1` enquiry route and the
 * gateway does the sending.
 *
 * The form is hidden from visitors unless the gateway says the listing accepts
 * enquiries. The listing's email address is never read, rendered or sent here.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The editable copy and its defaults. Visitor-facing text is Australian English.
 *
 * @return array<string, string>
 */
function agend_apps_records_listing_enquiry_defaults(): array {
	return array(
		'heading'             => __( 'Get in touch with {listing_name}', 'agend-apps-core' ),
		'first_label'         => __( 'First', 'agend-apps-core' ),
		'first_placeholder'   => '',
		'last_label'          => __( 'Last', 'agend-apps-core' ),
		'last_placeholder'    => '',
		'email_label'         => __( 'Email', 'agend-apps-core' ),
		'email_placeholder'   => '',
		'phone_label'         => __( 'Mobile', 'agend-apps-core' ),
		'phone_placeholder'   => '',
		'message_label'       => __( 'Message', 'agend-apps-core' ),
		'message_placeholder' => '',
		'button_text'         => __( 'Submit', 'agend-apps-core' ),
		'success_message'     => __( 'Thanks for contacting us! We will get in touch with you shortly.', 'agend-apps-core' ),
		'terms_label'         => __( 'I agree to the {terms_link}', 'agend-apps-core' ),
		'terms_link_text'     => __( 'terms and conditions', 'agend-apps-core' ),
		'heading_tag'         => 'h3',
		'folded_intro'        => '',
		'folded_label'        => __( 'Send an enquiry', 'agend-apps-core' ),
	);
}

/**
 * The element id that links to the form (`#agend-enquiry`), or '' once a form
 * on this page already holds it.
 *
 * Only one form per page is expected; a second one gets no id rather than a
 * duplicate one.
 *
 * @param bool $reset Forget a claimed id (the tests render several pages in one process).
 * @return string
 */
function agend_apps_records_listing_enquiry_claim_anchor( bool $reset = false ): string {
	static $claimed = false;

	if ( $reset ) {
		$claimed = false;
		return '';
	}

	if ( $claimed ) {
		return '';
	}

	$claimed = true;

	return 'agend-enquiry';
}

/**
 * Whether the form starts folded behind its heading.
 *
 * @param array $settings Surface settings.
 * @return bool
 */
function agend_apps_records_listing_enquiry_start_folded( array $settings ): bool {
	$value = $settings['start_folded'] ?? '';

	return 'yes' === $value || true === $value || '1' === $value || 1 === $value;
}

/**
 * The heading element an author chose, restricted to h1 to h6.
 *
 * @param array $settings Surface settings.
 * @return string
 */
function agend_apps_records_listing_enquiry_heading_tag( array $settings ): string {
	$tag = strtolower( (string) ( $settings['heading_tag'] ?? 'h3' ) );

	return in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $tag : 'h3';
}

/**
 * A setting's value, or its default when the setting is unset or blank.
 *
 * Only the heading may be blanked on purpose, which hides it, and the
 * placeholders default to none. Every other label falls back, because an empty
 * button or label is never what an author wants.
 *
 * @param array  $settings Surface settings.
 * @param string $key      Setting key.
 * @return string
 */
function agend_apps_records_listing_enquiry_text( array $settings, string $key ): string {
	$defaults = agend_apps_records_listing_enquiry_defaults();

	if ( 'heading' === $key && array_key_exists( 'heading', $settings ) ) {
		return trim( (string) $settings['heading'] );
	}

	if ( 'folded_intro' === $key ) {
		return isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	$value = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';

	return '' === $value ? (string) $defaults[ $key ] : $value;
}

/**
 * Decides whether the form renders for the listing in scope, and why not.
 *
 * `status` is one of:
 * - `ready`: render the form.
 * - `no_listing`: no record is in scope (the widget is on an ordinary page).
 * - `wrong_type`: the record in scope is not a listing.
 * - `unavailable`: the API key does not hold the enquiry scope.
 * - `no_captcha`: no captcha provider is fully configured and the development
 *   opt-out is not set. The route would refuse every submission, so the form
 *   is hidden.
 * - `closed`: the gateway says the listing does not accept enquiries, or
 *   could not say. Fails closed.
 *
 * `accepts_enquiries` and `enquiry_terms_url` are read from the record in
 * scope when it carries them (a listing detail does). A record that does not
 * (a search result, for one) is looked up through the cached listing detail.
 *
 * @param array $opts 'preview' / 'preview_type', passed straight to
 *                    agend_apps_records_resolve_record_context().
 * @return array{status: string, identifier: string, name: string, terms_url: string, is_preview: bool}
 */
function agend_apps_records_listing_enquiry_state( array $opts = array() ): array {
	$ctx   = agend_apps_records_resolve_record_context( $opts );
	$state = array(
		'status'     => 'no_listing',
		'identifier' => '',
		'name'       => '',
		'terms_url'  => '',
		'is_preview' => ! empty( $ctx['is_preview'] ),
	);

	if ( '' === $ctx['type'] ) {
		return $state;
	}

	if ( 'listing' !== $ctx['type'] ) {
		$state['status'] = 'wrong_type';
		return $state;
	}

	$record     = $ctx['record'];
	$identifier = isset( $record['slug'] ) && '' !== (string) $record['slug']
		? (string) $record['slug']
		: (string) ( $ctx['extra']['slug'] ?? ( $record['id'] ?? '' ) );

	$state['identifier'] = $identifier;
	$state['name']       = (string) ( agend_apps_records_field_value( 'common:title', 'listing', $record, $ctx['extra'] ) ?? '' );

	if ( function_exists( 'agend_apps_records_feature_available' ) && ! agend_apps_records_feature_available( 'directory_enquiry_form' ) ) {
		$state['status'] = 'unavailable';
		return $state;
	}

	if ( ! Agend_Apps_Captcha::submissions_open() ) {
		$state['status'] = 'no_captcha';
		return $state;
	}

	if ( ! empty( $record['_agend_preview_placeholder'] ) ) {
		$state['status'] = 'ready';
		return $state;
	}

	if ( '' === $identifier ) {
		$state['status'] = 'no_listing';
		return $state;
	}

	if ( ! array_key_exists( 'accepts_enquiries', $record ) ) {
		$response = agend_apps_directory_get_listing( $identifier );
		$record   = ( ! is_wp_error( $response ) && ! empty( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : array();
	}

	$terms_url          = isset( $record['enquiry_terms_url'] ) && is_string( $record['enquiry_terms_url'] ) ? esc_url_raw( $record['enquiry_terms_url'] ) : '';
	$state['terms_url'] = 1 === preg_match( '#^https?://#i', $terms_url ) ? $terms_url : '';
	$state['status']    = ! empty( $record['accepts_enquiries'] ) ? 'ready' : 'closed';

	return $state;
}

/**
 * Whether a visitor is offered the enquiry form for the listing in scope.
 *
 * The one decision the form and the record link's "Open enquiry form" action
 * share, so a link never points at a form that does not render.
 *
 * @param array $opts 'preview' / 'preview_type', as for the state; or 'state': a state already resolved.
 * @return bool
 */
function agend_apps_records_listing_enquiry_available( array $opts = array() ): bool {
	$state = is_array( $opts['state'] ?? null ) ? $opts['state'] : agend_apps_records_listing_enquiry_state( $opts );

	return 'ready' === $state['status'];
}

/**
 * A single labelled form control.
 *
 * @param string $id          Element id.
 * @param string $name        Field name.
 * @param string $type        `text`, `email`, `tel` or `textarea`.
 * @param string $label       Visible label.
 * @param string $placeholder Placeholder, or ''.
 * @param bool   $required    Whether the visitor must fill it in.
 * @param string $autocomplete Autocomplete token, or ''.
 * @param string $extra_class Extra wrapper class.
 * @return string
 */
function agend_apps_records_listing_enquiry_field( string $id, string $name, string $type, string $label, string $placeholder, bool $required, string $autocomplete = '', string $extra_class = '' ): string {
	$error_id = $id . '-error';
	$attrs    = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="agend-enquiry__input"'
		. ' aria-describedby="' . esc_attr( $error_id ) . '" data-agend-enquiry-field="' . esc_attr( $name ) . '"';

	if ( '' !== $placeholder ) {
		$attrs .= ' placeholder="' . esc_attr( $placeholder ) . '"';
	}

	if ( $required ) {
		$attrs .= ' required aria-required="true"';
	}

	if ( '' !== $autocomplete ) {
		$attrs .= ' autocomplete="' . esc_attr( $autocomplete ) . '"';
	}

	$limits = array(
		'first_name' => 100,
		'last_name'  => 100,
		'email'      => 254,
		'phone'      => 40,
		'message'    => 5000,
	);
	if ( isset( $limits[ $name ] ) ) {
		$attrs .= ' maxlength="' . (int) $limits[ $name ] . '"';
	}

	if ( 'textarea' === $type ) {
		$control = '<textarea' . $attrs . ' rows="6"></textarea>';
	} else {
		$control = '<input type="' . esc_attr( $type ) . '"' . $attrs . ' />';
	}

	$marker = $required ? ' <span class="agend-enquiry__required" aria-hidden="true">*</span>' : '';

	return '<div class="agend-enquiry__field agend-enquiry__field--' . esc_attr( $name ) . ( '' !== $extra_class ? ' ' . esc_attr( $extra_class ) : '' ) . '">'
		. '<label class="agend-enquiry__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $marker . '</label>'
		. $control
		. '<p class="agend-enquiry__error" id="' . esc_attr( $error_id ) . '" hidden></p>'
		. '</div>';
}

/**
 * The terms checkbox row, with the label's `{terms_link}` token replaced by a
 * link to the listing's terms.
 *
 * @param string $id        Element id.
 * @param array  $settings  Surface settings.
 * @param string $terms_url The listing's terms URL.
 * @return string
 */
function agend_apps_records_listing_enquiry_terms( string $id, array $settings, string $terms_url ): string {
	$label = agend_apps_records_listing_enquiry_text( $settings, 'terms_label' );
	$link  = '<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">'
		. esc_html( agend_apps_records_listing_enquiry_text( $settings, 'terms_link_text' ) ) . '</a>';

	$escaped = esc_html( $label );
	$escaped = false !== strpos( $escaped, '{terms_link}' )
		? str_replace( '{terms_link}', $link, $escaped )
		: $escaped . ' ' . $link;

	return '<div class="agend-enquiry__field agend-enquiry__field--terms">'
		. '<label class="agend-enquiry__terms" for="' . esc_attr( $id ) . '">'
		. '<input type="checkbox" id="' . esc_attr( $id ) . '" name="terms_accepted" value="1" required aria-required="true"'
		. ' aria-describedby="' . esc_attr( $id ) . '-error" data-agend-enquiry-field="terms_accepted" /> '
		. '<span>' . $escaped . '</span></label>'
		. '<p class="agend-enquiry__error" id="' . esc_attr( $id ) . '-error" hidden></p>'
		. '</div>';
}

/**
 * Renders the listing enquiry form.
 *
 * Returns '' when the form must not show: always on the live front end when
 * the listing does not accept enquiries, and in the editor only when there is
 * no listing in scope or the surface is unavailable (the adapter shows its
 * notice for those, from the same state).
 *
 * @param array $settings Surface settings: the text keys of
 *                        {@see agend_apps_records_listing_enquiry_defaults()}.
 * @param array $opts     'preview' / 'preview_type' as for the state; 'id'
 *                        (string): a caller-supplied element id so several
 *                        forms on one page never collide; and 'state': a state
 *                        the caller already resolved (the Elementor widget
 *                        reads it for its editor notice), so the listing is
 *                        not resolved twice.
 * @return string The rendered markup, or ''.
 */
function agend_apps_records_render_listing_enquiry( array $settings, array $opts = array() ): string {
	$state = is_array( $opts['state'] ?? null ) ? $opts['state'] : agend_apps_records_listing_enquiry_state( $opts );

	if ( ! agend_apps_records_listing_enquiry_available( array( 'state' => $state ) ) ) {
		return '';
	}

	$uid     = 'agend-enquiry-' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $opts['id'] ?? '' ) );
	$preview = ! empty( $opts['preview'] );
	$name    = '' !== $state['name'] ? $state['name'] : __( 'this listing', 'agend-apps-core' );

	$heading = str_replace( '{listing_name}', $name, agend_apps_records_listing_enquiry_text( $settings, 'heading' ) );

	$config = array(
		'endpoint'       => esc_url_raw( rest_url( 'agend-apps/v1/directory/listings/' . rawurlencode( $state['identifier'] ) . '/enquiries' ) ),
		'successMessage' => agend_apps_records_listing_enquiry_text( $settings, 'success_message' ),
		'captcha'        => Agend_Apps_Captcha::client_config(),
		'hasTerms'       => '' !== $state['terms_url'],
		'messages'       => array(
			'required'     => __( 'This field is required.', 'agend-apps-core' ),
			'email'        => __( 'Please enter a valid email address.', 'agend-apps-core' ),
			'tooLong'      => __( 'This is too long.', 'agend-apps-core' ),
			'messageShort' => __( 'Please write a message of at least 10 characters.', 'agend-apps-core' ),
			'terms'        => __( 'Please accept the terms to send your enquiry.', 'agend-apps-core' ),
			'fix'          => __( 'Please check the highlighted fields and try again.', 'agend-apps-core' ),
			'sending'      => __( 'Sending your message…', 'agend-apps-core' ),
			'captcha'      => __( 'We could not confirm you are not a robot. Please try again.', 'agend-apps-core' ),
			'captchaLoad'  => __( 'The security check could not load. Please reload the page and try again.', 'agend-apps-core' ),
			'challenge'    => __( 'Please complete the check above, then send your message again.', 'agend-apps-core' ),
			'session'      => __( 'Your session has expired. Please reload the page and try again.', 'agend-apps-core' ),
			'failed'       => __( 'Sorry, we could not send your enquiry right now. Please try again later.', 'agend-apps-core' ),
		),
	);

	$folded       = agend_apps_records_listing_enquiry_start_folded( $settings );
	$anchor       = agend_apps_records_listing_enquiry_claim_anchor();
	$tag          = agend_apps_records_listing_enquiry_heading_tag( $settings );
	$heading_html = '' !== $heading ? '<' . $tag . ' class="agend-enquiry__heading">' . esc_html( $heading ) . '</' . $tag . '>' : '';

	$html = '<div class="agend-enquiry' . ( $folded ? ' agend-enquiry--folded' : '' ) . '"'
		. ( '' !== $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '' )
		. ' data-agend-enquiry-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '"'
		. ( $preview ? ' data-agend-enquiry-preview="1"' : '' )
		. ( $folded ? ' data-agend-enquiry-folded="1"' : '' ) . '>';

	if ( $folded ) {
		$intro = agend_apps_records_listing_enquiry_text( $settings, 'folded_intro' );
		if ( '' === $heading_html ) {
			$heading_html = '<span class="agend-enquiry__heading">' . esc_html( agend_apps_records_listing_enquiry_text( $settings, 'folded_label' ) ) . '</span>';
		}
		// The editor shows the form open so it can be styled.
		$html .= '<details class="agend-enquiry__details"' . ( $preview ? ' open' : '' ) . '>'
			. '<summary class="agend-enquiry__summary">'
			. '<span class="agend-enquiry__summary-text">' . $heading_html
			. ( '' !== $intro ? '<span class="agend-enquiry__intro">' . esc_html( $intro ) . '</span>' : '' )
			. '</span><span class="agend-enquiry__toggle" aria-hidden="true"></span></summary>';
	} else {
		$html .= $heading_html;
	}

	$html .= '<form class="agend-enquiry__form" id="' . esc_attr( $uid ) . '" novalidate>';

	$html .= '<div class="agend-enquiry__row">'
		. agend_apps_records_listing_enquiry_field( $uid . '-first', 'first_name', 'text', agend_apps_records_listing_enquiry_text( $settings, 'first_label' ), agend_apps_records_listing_enquiry_text( $settings, 'first_placeholder' ), true, 'given-name' )
		. agend_apps_records_listing_enquiry_field( $uid . '-last', 'last_name', 'text', agend_apps_records_listing_enquiry_text( $settings, 'last_label' ), agend_apps_records_listing_enquiry_text( $settings, 'last_placeholder' ), true, 'family-name' )
		. '</div>';

	$html .= '<div class="agend-enquiry__row">'
		. agend_apps_records_listing_enquiry_field( $uid . '-email', 'email', 'email', agend_apps_records_listing_enquiry_text( $settings, 'email_label' ), agend_apps_records_listing_enquiry_text( $settings, 'email_placeholder' ), true, 'email' )
		. agend_apps_records_listing_enquiry_field( $uid . '-phone', 'phone', 'tel', agend_apps_records_listing_enquiry_text( $settings, 'phone_label' ), agend_apps_records_listing_enquiry_text( $settings, 'phone_placeholder' ), false, 'tel' )
		. '</div>';

	$html .= '<div class="agend-enquiry__row agend-enquiry__row--full">'
		. agend_apps_records_listing_enquiry_field( $uid . '-message', 'message', 'textarea', agend_apps_records_listing_enquiry_text( $settings, 'message_label' ), agend_apps_records_listing_enquiry_text( $settings, 'message_placeholder' ), true )
		. '</div>';

	if ( '' !== $state['terms_url'] ) {
		$html .= '<div class="agend-enquiry__row agend-enquiry__row--full">'
			. agend_apps_records_listing_enquiry_terms( $uid . '-terms', $settings, $state['terms_url'] )
			. '</div>';
	}

	if ( null !== $config['captcha'] && Agend_Apps_Captcha::PROVIDER_TURNSTILE === $config['captcha']['provider'] ) {
		$html .= '<div class="agend-enquiry__captcha" data-agend-enquiry-captcha></div>';
	}

	$html .= '<div class="agend-enquiry__actions">'
		. '<button type="submit" class="agend-enquiry__submit" data-agend-enquiry-submit>' . esc_html( agend_apps_records_listing_enquiry_text( $settings, 'button_text' ) ) . '</button>'
		. '</div>';

	$html .= '</form>';
	$html .= '<div class="agend-enquiry__status" role="status" aria-live="polite" tabindex="-1" data-agend-enquiry-status></div>';
	$html .= $folded ? '</details></div>' : '</div>';

	return $html;
}
