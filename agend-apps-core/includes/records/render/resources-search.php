<?php
/**
 * Server render of the resource search surface.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * The page makes no gateway call and is the same for every viewer, so a
 * full-page cache can hold it. What it draws is a plain search form that sends
 * a visitor to Atlas's own public search page, which runs a keyword search and
 * costs the association nothing. That form works with no script at all.
 *
 * A small script upgrades it. For a signed-in member it shows results in the
 * page as they type, through the site's own search route with the member's
 * view of the library. For a visitor it does the same only when the editor
 * chose live results, which makes the gateway embed each query on the
 * association's AI allowance; the route then needs the proof this file puts in
 * the page for that case {@see agend_apps_records_resources_visitor_token()}.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../schema/resources-search.php';
require_once __DIR__ . '/resources-common.php';

/**
 * The fewest characters a live search starts at. Shorter queries match too
 * much to be worth a gateway call.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_RESOURCES_SEARCH_MIN_CHARS = 3;

/**
 * Builds the render config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array The values the renderer reads, typed and bounded.
 */
function agend_apps_records_resources_search_build_config( array $s ): array {
	$colours = agend_apps_records_resources_colours( $s );
	$on      = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return agend_apps_records_resources_on( $s, $key, $fallback );
	};
	$text    = static function ( string $key, string $fallback ) use ( $s ): string {
		return array_key_exists( $key, $s ) ? trim( (string) $s[ $key ] ) : $fallback;
	};

	$suggestions = $on( 'show_suggestions', '' )
		? agend_apps_records_resources_list( $s['suggestions'] ?? '', ',', AGEND_APPS_RECORDS_RESOURCES_SEARCH_SUGGESTIONS_MAX, 60 )
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
		'results'     => array(
			'max'     => max( 1, min( AGEND_APPS_RECORDS_RESOURCES_SEARCH_RESULTS_MAX, (int) ( $s['max_results'] ?? 6 ) ) ),
			'excerpt' => $on( 'show_excerpt' ),
			'seeAll'  => $text( 'see_all_label', '' ),
		),
		'note'        => array(
			'show' => $on( 'show_members_note' ),
			'text' => $text( 'members_note', '' ),
		),
		'visitorLive' => 'live' === ( $s['visitor_mode'] ?? 'link_out' ),
		'messages'    => array(
			'none'  => $text( 'no_results_text', '' ),
			'error' => $text( 'error_text', '' ),
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
 * @param array $config The surface config.
 * @return array
 */
function agend_apps_records_resources_search_messages( array $config ): array {
	return array(
		'none'       => '' !== $config['messages']['none'] ? $config['messages']['none'] : __( 'Nothing matched your search. Try different words.', 'agend-apps-core' ),
		'error'      => '' !== $config['messages']['error'] ? $config['messages']['error'] : __( 'Search is not available right now. Please try again in a moment.', 'agend-apps-core' ),
		'busy'       => __( 'You are searching quickly. Wait a moment, then try again.', 'agend-apps-core' ),
		'searching'  => __( 'Searching…', 'agend-apps-core' ),
		'tooShort'   => sprintf(
			/* translators: %d: the fewest characters a live search starts at. */
			__( 'Type at least %d characters to search.', 'agend-apps-core' ),
			AGEND_APPS_RECORDS_RESOURCES_SEARCH_MIN_CHARS
		),
		'signInNote' => __( 'Sign in to Atlas to read the full page.', 'agend-apps-core' ),
		'notLinked'  => __( 'We could not find your member details. Sign in again to search the whole library.', 'agend-apps-core' ),
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
function agend_apps_records_render_resources_search( array $settings, array $opts = array() ): string {
	static $instance = 0;

	$preview = ! empty( $opts['preview'] );
	$gate    = agend_apps_records_surface_gate( 'resources-search', $preview );

	if ( null !== $gate ) {
		return $gate;
	}

	// A search box inside a card template would be drawn once per card.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config   = agend_apps_records_resources_search_build_config( $settings );
	$search   = function_exists( 'agend_apps_atlas_public_search_url' ) ? agend_apps_atlas_public_search_url() : '';
	$linkable = '' !== $search;

	// Without a search page to send a visitor to, the plain form has nowhere to
	// go. A member still has live results (they link nowhere, but they read), so
	// the surface stays in the page, hidden until the script finds a member; and
	// a visitor-live page needs nothing from the search page either.
	$visitor_form = $linkable || $config['visitorLive'];
	$hidden       = ! $visitor_form && ! $preview;

	++$instance;
	$uid = 'agend-resources-search-' . $instance;

	$client = array(
		'endpoint'    => esc_url_raw( rest_url( 'agend-apps/v1/resources/search' ) ),
		'minChars'    => AGEND_APPS_RECORDS_RESOURCES_SEARCH_MIN_CHARS,
		'limit'       => $config['results']['max'],
		'excerpt'     => $config['results']['excerpt'],
		'seeAllLabel' => '' !== $config['results']['seeAll'] ? $config['results']['seeAll'] : __( 'See all results', 'agend-apps-core' ),
		'seeAllUrl'   => $search,
		'visitorLive' => $config['visitorLive'],
		'messages'    => agend_apps_records_resources_search_messages( $config ),
	);

	if ( $config['visitorLive'] ) {
		$client['visitorToken'] = agend_apps_records_resources_visitor_token();
	}

	$style   = agend_apps_records_resources_style( $config['colours'], $config['radius'] );
	$heading = $config['heading'];
	$label   = '' !== $config['button'] ? $config['button'] : __( 'Search', 'agend-apps-core' );
	$hint    = '' !== $config['placeholder'] ? $config['placeholder'] : __( 'Search guides, policies and how-tos', 'agend-apps-core' );

	ob_start();
	?>
	<div class="agend-res agend-resources-search" style="<?php echo esc_attr( $style ); ?>" data-agend-resources-search="<?php echo esc_attr( (string) wp_json_encode( $client ) ); ?>"<?php echo $hidden ? ' hidden' : ''; ?>>
		<?php echo agend_apps_records_resources_heading( $heading['show'], $heading['title'], $heading['subtitle'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper. ?>
		<?php if ( ! $linkable && $preview ) : ?>
			<?php
			echo agend_apps_records_resources_editor_note( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper.
				$config['visitorLive']
					? __( 'Connect your Agend account in Agend Apps settings to link results and the full results page.', 'agend-apps-core' )
					: __( 'Connect your Agend account in Agend Apps settings so visitors can be sent to your search page. Until then visitors see nothing here.', 'agend-apps-core' )
			);
			?>
		<?php endif; ?>
		<form class="agend-resources-search__form" role="search" method="get" action="<?php echo esc_url( $search ); ?>">
			<label class="agend-res__sr" for="<?php echo esc_attr( $uid ); ?>"><?php echo esc_html( $hint ); ?></label>
			<div class="agend-resources-search__field">
				<span class="agend-resources-search__icon" aria-hidden="true"><?php echo agend_apps_records_resources_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup. ?></span>
				<input id="<?php echo esc_attr( $uid ); ?>" class="agend-resources-search__input" type="search" name="q" autocomplete="off" maxlength="200" placeholder="<?php echo esc_attr( $hint ); ?>" aria-controls="<?php echo esc_attr( $uid ); ?>-results">
				<button type="submit" class="agend-resources-search__button"><?php echo esc_html( $label ); ?></button>
			</div>
		</form>
		<?php if ( array() !== $config['suggestions'] ) : ?>
			<ul class="agend-resources-search__suggestions" role="list">
				<?php foreach ( $config['suggestions'] as $suggestion ) : ?>
					<li class="agend-resources-search__suggestion">
						<a class="agend-resources-search__chip exclude-agend-theme-link" href="<?php echo esc_url( $linkable ? agend_apps_atlas_public_search_url( $suggestion ) : '#' ); ?>" data-agend-suggest="<?php echo esc_attr( $suggestion ); ?>"><?php echo esc_html( $suggestion ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<div class="agend-resources-search__results" id="<?php echo esc_attr( $uid ); ?>-results" aria-live="polite" hidden></div>
		<?php if ( $config['note']['show'] && '' !== $config['note']['text'] ) : ?>
			<p class="agend-res__note agend-resources-search__note"><?php echo esc_html( $config['note']['text'] ); ?></p>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}
