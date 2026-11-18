<?php
/**
 * Server render of the resource Ask surface: a question box that answers from
 * the association's own Atlas library, with the pages the answer came from.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * Only a signed-in member can ask. Each answer is written by a model on the
 * association's one monthly allowance, so an open box on a public page would
 * let anyone spend it (and, once spent, take Ask away from members too). The
 * page makes no gateway call and is the same for every viewer, so a full-page
 * cache can hold it: the box is in the page but hidden, with a sign-in prompt
 * beside it that is shown, and a small script swaps them when it finds a
 * signed-in member. Questions go to the site's own Ask route, which limits each
 * member and the site as a whole before it calls the gateway.
 *
 * Whether the account is entitled to Ask is learned from the last verdict an
 * ask returned {@see agend_apps_atlas_ask_state()}. While the verdict is "not
 * entitled" the surface draws nothing for a visitor, and tells an editor why.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../schema/resources-ask.php';
require_once __DIR__ . '/resources-common.php';

/**
 * The longest question the box takes, in characters.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_RESOURCES_ASK_MAX_CHARS = 500;

/**
 * Builds the render config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array The values the renderer reads, typed and bounded.
 */
function agend_apps_records_resources_ask_build_config( array $s ): array {
	$colours = agend_apps_records_resources_colours( $s );
	$on      = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return agend_apps_records_resources_on( $s, $key, $fallback );
	};
	$text    = static function ( string $key, string $fallback ) use ( $s ): string {
		return array_key_exists( $key, $s ) ? trim( (string) $s[ $key ] ) : $fallback;
	};

	$suggestions = $on( 'show_suggestions', '' )
		? agend_apps_records_resources_list( $s['suggestions'] ?? '', "\n", AGEND_APPS_RECORDS_RESOURCES_ASK_SUGGESTIONS_MAX, 140 )
		: array();

	return array(
		'heading'     => array(
			'show'     => $on( 'show_heading' ),
			'title'    => $text( 'heading_text', '' ),
			'subtitle' => $text( 'subheading_text', '' ),
		),
		'placeholder' => $text( 'placeholder', '' ),
		'button'      => $text( 'button_label', '' ),
		'suggestions' => $suggestions,
		'sources'     => $on( 'show_sources' ),
		'signedOut'   => array(
			'show'     => 'hide' !== ( $s['signed_out_behaviour'] ?? 'prompt' ),
			'message'  => $text( 'signed_out_message', '' ),
			'label'    => $text( 'sign_in_label', '' ),
			'url'      => agend_apps_records_resources_url( $s['sign_in_url'] ?? '' ),
		),
		'radius'      => max( 0, min( 48, (int) ( $s['card_radius'] ?? 10 ) ) ),
		'colours'     => $colours['colours'],
		'theme'       => array(
			'colourSource' => $colours['source'],
		),
	);
}

/**
 * Every label and message the script draws, so all of it is translatable.
 *
 * @return array
 */
function agend_apps_records_resources_ask_messages(): array {
	return array(
		'asked'        => __( 'You asked:', 'agend-apps-core' ),
		'thinking'     => __( 'Looking through our resources…', 'agend-apps-core' ),
		'tooShort'     => __( 'Type a question first.', 'agend-apps-core' ),
		'error'        => __( 'We could not get an answer just now. Please try again in a moment.', 'agend-apps-core' ),
		'busy'         => __( 'You have asked a few questions in a short time. Wait a little, then ask again.', 'agend-apps-core' ),
		'unavailable'  => __( 'Questions are not available at the moment.', 'agend-apps-core' ),
		'notLinked'    => __( 'We could not find your member details. Sign in again to ask a question.', 'agend-apps-core' ),
		'lowConfidence' => __( 'This answer may be incomplete. Check the sources before relying on it.', 'agend-apps-core' ),
		'sources'      => __( 'Sources', 'agend-apps-core' ),
		'signInNote'   => __( 'Sign in to Atlas to read the full page.', 'agend-apps-core' ),
		'noSources'    => __( 'No sources were listed for this answer.', 'agend-apps-core' ),
		'matches'      => __( 'These pages may help', 'agend-apps-core' ),
		'contact'      => __( 'You can also contact us:', 'agend-apps-core' ),
		'refusalTitle' => __( 'We could not answer that', 'agend-apps-core' ),
		'source'       => __( 'Source', 'agend-apps-core' ),
	);
}

/**
 * Renders the surface on the front end, returned as HTML.
 *
 * @param array $settings Surface settings (Elementor-shaped values).
 * @param array $opts     Render opts: `preview` is true when an editor, not a
 *                        visitor, is looking at it in the page builder.
 * @return string
 */
function agend_apps_records_render_resources_ask( array $settings, array $opts = array() ): string {
	static $instance = 0;

	$preview = ! empty( $opts['preview'] );
	$gate    = agend_apps_records_surface_gate( 'resources-ask', $preview );

	if ( null !== $gate ) {
		return $gate;
	}

	// A question box inside a card template would be drawn once per card.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	// The account answered "not entitled" to the last question: no box can
	// answer. Visitors and members see nothing; an editor is told why.
	if ( function_exists( 'agend_apps_atlas_ask_state' ) && 'not_entitled' === agend_apps_atlas_ask_state() ) {
		if ( $preview || current_user_can( 'edit_posts' ) ) {
			return agend_apps_records_resources_editor_note( __( 'Ask is not part of your Agend plan yet, so this box is hidden from everyone. Ask your Agend contact about adding it. The box returns on its own once it is.', 'agend-apps-core' ) );
		}

		return '';
	}

	$config = agend_apps_records_resources_ask_build_config( $settings );

	++$instance;
	$uid = 'agend-resources-ask-' . $instance;

	$prompt = $config['signedOut']['show'];
	$label  = '' !== $config['button'] ? $config['button'] : __( 'Ask', 'agend-apps-core' );
	$hint   = '' !== $config['placeholder'] ? $config['placeholder'] : __( 'For example: How do I make a complaint?', 'agend-apps-core' );

	$client = array(
		'endpoint'  => esc_url_raw( rest_url( 'agend-apps/v1/resources/ask' ) ),
		'maxChars'  => AGEND_APPS_RECORDS_RESOURCES_ASK_MAX_CHARS,
		'sources'   => $config['sources'],
		'messages'  => agend_apps_records_resources_ask_messages(),
	);

	$style   = agend_apps_records_resources_style( $config['colours'], $config['radius'] );
	$heading = $config['heading'];

	$sign_in_label = '' !== $config['signedOut']['label'] ? $config['signedOut']['label'] : __( 'Sign in', 'agend-apps-core' );
	$sign_in_text  = '' !== $config['signedOut']['message'] ? $config['signedOut']['message'] : __( 'Sign in to ask a question.', 'agend-apps-core' );

	ob_start();
	?>
	<section class="agend-res agend-resources-ask" style="<?php echo esc_attr( $style ); ?>" data-agend-resources-ask="<?php echo esc_attr( (string) wp_json_encode( $client ) ); ?>"<?php echo ( ! $prompt ) ? ' hidden' : ''; ?>>
		<?php echo agend_apps_records_resources_heading( $heading['show'], $heading['title'], $heading['subtitle'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper. ?>
		<?php if ( $prompt ) : ?>
			<div class="agend-resources-ask__signed-out">
				<span class="agend-resources-ask__signed-out-icon" aria-hidden="true"><?php echo agend_apps_records_resources_icon( 'ask' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
				<p class="agend-resources-ask__signed-out-text"><?php echo esc_html( $sign_in_text ); ?></p>
				<a class="agend-res__button exclude-agend-theme-link" href="<?php echo esc_url( agend_apps_records_resources_sign_in_url( $config['signedOut']['url'] ) ); ?>"><?php echo esc_html( $sign_in_label ); ?></a>
			</div>
		<?php endif; ?>
		<div class="agend-resources-ask__panel" hidden>
			<form class="agend-resources-ask__form" novalidate>
				<label class="agend-res__sr" for="<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'Your question', 'agend-apps-core' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>" class="agend-resources-ask__input" name="question" rows="2" maxlength="<?php echo (int) AGEND_APPS_RECORDS_RESOURCES_ASK_MAX_CHARS; ?>" placeholder="<?php echo esc_attr( $hint ); ?>"></textarea>
				<button type="submit" class="agend-resources-ask__button"><?php echo agend_apps_records_resources_icon( 'ask' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?><span><?php echo esc_html( $label ); ?></span></button>
			</form>
			<?php if ( array() !== $config['suggestions'] ) : ?>
				<ul class="agend-resources-ask__suggestions" role="list">
					<?php foreach ( $config['suggestions'] as $suggestion ) : ?>
						<li class="agend-resources-ask__suggestion">
							<button type="button" class="agend-resources-ask__chip" data-agend-suggest="<?php echo esc_attr( $suggestion ); ?>"><?php echo esc_html( $suggestion ); ?></button>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="agend-resources-ask__answer" aria-live="polite" hidden></div>
		</div>
	</section>
	<?php

	return (string) ob_get_clean();
}
