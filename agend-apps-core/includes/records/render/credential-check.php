<?php
/**
 * Server render of the credential check surface: a form that checks a
 * certificate or badge code against the Agend learning record.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * The HTML is the same for every visitor and holds no result: a visitor's code
 * goes to the `lms/credentials/verify` route from a script, so a cached page
 * can serve a printed link or a scanned QR code, and nothing about a
 * credential is ever in page markup. The route decides everything that can be
 * learned (see includes/credential-check.php); this file only draws the form
 * and hands the script its wording.
 *
 * Fails closed like the listing enquiry: without a captcha (or the explicit
 * development opt-out) a visitor sees nothing, and the route refuses.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every renderer asks the surface gate before it fetches or draws anything;
// callers that load one renderer on its own (the unit tests) need it present.
require_once __DIR__ . '/../surface-gate.php';

// The text defaults are the schema's, so a blank setting falls back to them.
require_once __DIR__ . '/../schema/credential-check.php';

/**
 * A setting that may be left blank on purpose: it then hides its text rather
 * than falling back to the default.
 *
 * @return string[]
 */
function agend_apps_records_credential_check_blankable(): array {
	return array( 'intro_text', 'input_placeholder', 'help_text', 'holder_note', 'help_label' );
}

/**
 * The schema's default for a setting.
 *
 * @param string $name Setting name.
 * @return mixed|null Null when the surface has no such setting.
 */
function agend_apps_records_credential_check_default( string $name ) {
	foreach ( agend_apps_records_schema_credential_check()['sections'] as $section ) {
		foreach ( $section['fields'] as $field ) {
			if ( $name === $field['name'] ) {
				return $field['default'] ?? null;
			}
		}
	}

	return null;
}

/**
 * A text setting's value: what the editor typed, or the default when it is
 * blank (unless blank is allowed, see
 * {@see agend_apps_records_credential_check_blankable()}).
 *
 * @param array  $s   Surface settings.
 * @param string $key Setting name.
 * @return string
 */
function agend_apps_records_credential_check_text( array $s, string $key ): string {
	$value = isset( $s[ $key ] ) && is_scalar( $s[ $key ] ) ? trim( (string) $s[ $key ] ) : '';

	// A saved blank on a setting that may be blank is the editor's choice. A
	// setting that was never saved (a page made before it existed) has no
	// choice to honour and takes the default.
	if ( '' === $value && array_key_exists( $key, $s ) && in_array( $key, agend_apps_records_credential_check_blankable(), true ) ) {
		return '';
	}

	return '' === $value ? (string) agend_apps_records_credential_check_default( $key ) : $value;
}

/**
 * Builds the render config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array The values the renderer and the script read, typed and bounded.
 */
function agend_apps_records_credential_check_build_config( array $s ): array {
	$colours = agend_apps_records_resolve_colours(
		array( 'inherit_colours' => (string) ( $s['inherit_colours'] ?? 'yes' ) ) + $s,
		array(
			'heading' => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['heading'],
			'body'    => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['body'],
			'accent'  => AGEND_APPS_RECORDS_COLOUR_DEFAULTS['accent'],
		)
	);

	$on = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return 'yes' === ( $s[ $key ] ?? $fallback );
	};

	$param = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $s['prefill_param'] ?? '' ) );
	$param = '' === $param ? 'code' : substr( (string) $param, 0, 40 );

	$help_url = function_exists( 'agend_apps_records_normalise_url_setting' ) ? agend_apps_records_normalise_url_setting( $s['help_url'] ?? '' ) : '';
	$help_url = 1 === preg_match( '#^https?://#i', $help_url ) || 0 === strpos( $help_url, '/' ) ? esc_url_raw( $help_url ) : '';

	$text = static function ( string $key ) use ( $s ): string {
		return agend_apps_records_credential_check_text( $s, $key );
	};

	return array(
		'heading' => array(
			'show'  => $on( 'show_heading' ),
			'title' => $text( 'heading_text' ),
			'intro' => $text( 'intro_text' ),
		),
		'form'    => array(
			'label'       => $text( 'input_label' ),
			'placeholder' => $text( 'input_placeholder' ),
			'hint'        => $text( 'help_text' ),
			'button'      => $text( 'button_label' ),
			'param'       => $param,
			'autoCheck'   => $on( 'auto_check' ),
		),
		'show'    => array(
			'number'    => $on( 'show_number' ),
			'issued'    => $on( 'show_issued' ),
			'course'    => $on( 'show_course' ),
			'cpd'       => $on( 'show_cpd' ),
			'instructor' => $on( 'show_instructor' ),
			'topics'    => $on( 'show_topics' ),
			'image'     => $on( 'show_image' ),
			'checkedAt' => $on( 'show_checked_at' ),
			// Off unless the editor turns it on: see the schema.
			'holder'    => $on( 'show_holder', '' ),
		),
		'text'    => array(
			'validTitle'  => $text( 'valid_title' ),
			'checkedText' => $text( 'checked_text' ),
			'validNote'   => $text( 'valid_note' ),
			'holderNote'  => $text( 'holder_note' ),
			'notFound'    => $text( 'not_found_text' ),
			'notFoundHelp' => $text( 'not_found_help' ),
			'invalid'     => $text( 'invalid_text' ),
			'busy'        => $text( 'busy_text' ),
			'captcha'     => $text( 'captcha_text' ),
			'unavailable' => $text( 'unavailable_text' ),
		),
		'help'    => array(
			'url'   => $help_url,
			'label' => $text( 'help_label' ),
		),
		'colours' => $colours['colours'],
		'theme'   => array(
			'colourSource' => $colours['source'],
		),
	);
}

/**
 * The colour variables the stylesheet reads.
 *
 * @param array $config The surface config.
 * @return string
 */
function agend_apps_records_credential_check_inline_style( array $config ): string {
	return sprintf(
		'--agend-cc-heading:%1$s;--agend-cc-body:%2$s;--agend-cc-accent:%3$s;',
		esc_attr( $config['colours']['heading'] ),
		esc_attr( $config['colours']['body'] ),
		esc_attr( $config['colours']['accent'] )
	);
}

/**
 * Decides whether the form renders for a visitor, and why not.
 *
 * `status` is `ready`, or `no_captcha`: no provider is fully set up and the
 * development opt-out is off, so the route would refuse every check and the
 * form is hidden. Fails closed.
 *
 * @return array{status: string, badges: bool}
 */
function agend_apps_records_credential_check_state(): array {
	return array(
		'status' => agend_apps_credentials_open() ? 'ready' : 'no_captcha',
		'badges' => agend_apps_credentials_badges_available(),
	);
}

/**
 * What an editor is told when the form would not show a visitor, or would
 * check certificates only. Empty strings when there is nothing to say.
 *
 * @param array $state {@see agend_apps_records_credential_check_state()}.
 * @return string[] Plain-text notices.
 */
function agend_apps_records_credential_check_notices( array $state ): array {
	$notices = array();

	if ( 'no_captcha' === $state['status'] ) {
		$notices[] = __( 'No security check is set up, so visitors will not see this form. Set a provider, site key and secret key in Settings > Agend Apps > Form Captcha.', 'agend-apps-core' );
	}

	if ( ! $state['badges'] ) {
		$notices[] = function_exists( 'agend_apps_records_feature_missing_scope_notice' )
			? agend_apps_records_feature_missing_scope_notice( 'lms_credential_badges' )
			: __( 'This site can check certificates only. Badge codes are refused until the Agend key is allowed to check badges.', 'agend-apps-core' );
	}

	return array_values( array_filter( $notices, 'strlen' ) );
}

/**
 * Adds the credential check's notices to the block editor, above the block.
 *
 * @param array  $notices Notices so far.
 * @param string $surface Surface id.
 * @return array
 */
function agend_apps_records_credential_check_block_notices( array $notices, string $surface ): array {
	if ( 'credential-check' !== $surface ) {
		return $notices;
	}

	foreach ( agend_apps_records_credential_check_notices( agend_apps_records_credential_check_state() ) as $text ) {
		$notices[] = array(
			'status' => 'warning',
			'text'   => $text,
		);
	}

	return $notices;
}
add_filter( 'agend_apps_records_block_surface_notices', 'agend_apps_records_credential_check_block_notices', 10, 2 );

/**
 * The words the script needs: everything it can show, as one list, so the
 * script has no wording of its own to translate.
 *
 * @param array $config The surface config.
 * @return array<string, string>
 */
function agend_apps_records_credential_check_labels( array $config ): array {
	$text = $config['text'];

	return array(
		'validTitle'         => $text['validTitle'],
		'checkedText'        => $text['checkedText'],
		'validNote'          => $text['validNote'],
		'holderNote'         => $text['holderNote'],
		'notFound'           => $text['notFound'],
		'notFoundHelp'       => $text['notFoundHelp'],
		'invalid'            => $text['invalid'],
		'invalidEmpty'       => __( 'Enter a code to check.', 'agend-apps-core' ),
		'invalidUuid'        => __( 'That looks like an internal badge number, not a badge link. Ask the person who earned the badge for the link to their badge.', 'agend-apps-core' ),
		'invalidBadges'      => __( 'This site checks certificate codes only. Badge codes cannot be checked here.', 'agend-apps-core' ),
		'busy'               => $text['busy'],
		'captcha'            => $text['captcha'],
		'captchaLoad'        => __( 'The security check could not load. Please reload the page and try again.', 'agend-apps-core' ),
		'challenge'          => __( 'Please complete the security check above, then press the button again.', 'agend-apps-core' ),
		'unavailable'        => $text['unavailable'],
		'checking'           => __( 'Checking', 'agend-apps-core' ),
		'unitSeconds'        => __( 'seconds', 'agend-apps-core' ),
		'unitMinutes'        => __( 'minutes', 'agend-apps-core' ),
		'ended'              => __( 'No longer valid', 'agend-apps-core' ),
		'endedNote'          => __( 'This credential is in the Agend learning record, but it is no longer valid.', 'agend-apps-core' ),
		'validUntil'         => __( 'Valid until', 'agend-apps-core' ),
		'certificate'        => __( 'Certificate', 'agend-apps-core' ),
		'badge'              => __( 'Badge', 'agend-apps-core' ),
		'unknownCourse'      => __( 'Course details unavailable', 'agend-apps-core' ),
		'rowNumber'          => __( 'Certificate number', 'agend-apps-core' ),
		'rowCourse'          => __( 'Course', 'agend-apps-core' ),
		'rowIssued'          => __( 'Issued', 'agend-apps-core' ),
		'rowEarned'          => __( 'Earned', 'agend-apps-core' ),
		'rowCpd'             => __( 'CPD points', 'agend-apps-core' ),
		'rowInstructor'      => __( 'Instructor', 'agend-apps-core' ),
		'rowTopics'          => __( 'Topics', 'agend-apps-core' ),
		'rowHolder'          => __( 'Earned by', 'agend-apps-core' ),
		'rowChecked'         => __( 'Checked on', 'agend-apps-core' ),
		'badgePicture'       => __( 'Badge picture', 'agend-apps-core' ),
	);
}

/**
 * Renders the credential check form.
 *
 * Returns '' for a visitor when the form must not show: the surface gate is
 * closed, the widget sits inside a card or detail template, or no captcha is
 * set up. An editor (`preview`) still gets the form so it can be styled; its
 * script never submits.
 *
 * @param array $settings Surface settings (Elementor-shaped values).
 * @param array $opts     'preview' (bool): drawn for an editor; 'id' (string):
 *                        a caller-supplied element id so several forms on one
 *                        page never collide.
 * @return string
 */
function agend_apps_records_render_credential_check( array $settings, array $opts = array() ): string {
	$preview = ! empty( $opts['preview'] );
	$gate    = agend_apps_records_surface_gate( 'credential-check', $preview );

	if ( null !== $gate ) {
		return $gate;
	}

	// A lookup form inside a card template would be drawn once per card.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$state = agend_apps_records_credential_check_state();

	if ( 'ready' !== $state['status'] && ! $preview ) {
		return '';
	}

	$config = agend_apps_records_credential_check_build_config( $settings );

	// An id the caller gave (the Elementor widget's own), else the next number
	// on this page, so two forms on one page never share an element id.
	static $count = 0;
	++$count;
	$suffix = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $opts['id'] ?? '' ) );
	$uid    = 'agend-credential-' . ( '' !== $suffix ? $suffix : (string) $count );

	$heading = $config['heading'];
	$form    = $config['form'];
	$captcha = Agend_Apps_Captcha::client_config();

	$client = array(
		'endpoint'  => esc_url_raw( rest_url( 'agend-apps/v1/lms/credentials/verify' ) ),
		'captcha'   => $captcha,
		'param'     => $form['param'],
		'autoCheck' => $form['autoCheck'],
		// Only a page set to show a badge earner's name can ask for it.
		'holderKey' => $config['show']['holder'] ? agend_apps_credentials_holder_key() : '',
		'show'      => $config['show'],
		'help'      => $config['help'],
		'text'      => agend_apps_records_credential_check_labels( $config ),
	);

	$captcha_box = null !== $captcha && in_array( $captcha['provider'], array( Agend_Apps_Captcha::PROVIDER_TURNSTILE, Agend_Apps_Captcha::PROVIDER_RECAPTCHA_V2 ), true );

	ob_start();
	?>
	<div class="agend-credential-check" style="<?php echo esc_attr( agend_apps_records_credential_check_inline_style( $config ) ); ?>" data-agend-credential-config="<?php echo esc_attr( (string) wp_json_encode( $client ) ); ?>"<?php echo $preview ? ' data-agend-credential-preview="1"' : ''; ?>>
		<?php if ( $heading['show'] && ( '' !== $heading['title'] || '' !== $heading['intro'] ) ) : ?>
			<div class="agend-credential-check__heading">
				<?php if ( '' !== $heading['title'] ) : ?>
					<h2 class="agend-credential-check__title"><?php echo esc_html( $heading['title'] ); ?></h2>
				<?php endif; ?>
				<?php if ( '' !== $heading['intro'] ) : ?>
					<p class="agend-credential-check__intro"><?php echo esc_html( $heading['intro'] ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<form class="agend-credential-check__form" id="<?php echo esc_attr( $uid ); ?>" novalidate>
			<label class="agend-credential-check__label" for="<?php echo esc_attr( $uid ); ?>-code"><?php echo esc_html( $form['label'] ); ?></label>
			<div class="agend-credential-check__control">
				<input
					class="agend-credential-check__input"
					type="text"
					id="<?php echo esc_attr( $uid ); ?>-code"
					name="code"
					inputmode="text"
					autocomplete="off"
					autocapitalize="off"
					autocorrect="off"
					spellcheck="false"
					maxlength="2000"
					<?php echo '' !== $form['placeholder'] ? 'placeholder="' . esc_attr( $form['placeholder'] ) . '"' : ''; ?>
					<?php echo '' !== $form['hint'] ? 'aria-describedby="' . esc_attr( $uid ) . '-hint"' : ''; ?>
					data-agend-credential-input
				>
				<button type="submit" class="agend-credential-check__button exclude-agend-theme-link" data-agend-credential-submit><?php echo esc_html( $form['button'] ); ?></button>
			</div>
			<?php if ( '' !== $form['hint'] ) : ?>
				<p class="agend-credential-check__hint" id="<?php echo esc_attr( $uid ); ?>-hint"><?php echo esc_html( $form['hint'] ); ?></p>
			<?php endif; ?>
			<?php if ( $captcha_box ) : ?>
				<div class="agend-credential-check__captcha" data-agend-credential-captcha></div>
			<?php endif; ?>
		</form>
		<div class="agend-credential-check__result" tabindex="-1" data-agend-credential-result></div>
		<p class="agend-credential-check__sr" role="status" aria-live="polite" data-agend-credential-live></p>
		<noscript>
			<p class="agend-credential-check__noscript"><?php esc_html_e( 'JavaScript is needed to check a credential. Please turn it on and reload the page.', 'agend-apps-core' ); ?></p>
		</noscript>
	</div>
	<?php

	return (string) ob_get_clean();
}
