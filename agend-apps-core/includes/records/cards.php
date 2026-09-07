<?php
/**
 * Renders catalogue cards from an Elementor template, one per record.
 *
 * Used by the catalogue widgets for the first page and by the REST fragment
 * endpoint for every page after that, so both produce the same markup.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders templated cards for a list of records.
 *
 * Options: `card_link_whole` (bool, default true: the card is one anchor),
 * `host_page_id` (int, the page the catalogue sits on; the detail URL falls
 * back to it when no dedicated page is configured), `with_css` (bool, inline
 * the template CSS with the first card; for fragments requested from a page
 * that did not carry the stylesheet).
 *
 * @param string $type        'event' or 'course'.
 * @param int    $template_id The card template (a registered {@see Agend_Apps_Template_Renderer} template id).
 * @param array  $records     Records from the list endpoint.
 * @param array  $opts        Options.
 * @return array<int, array{slug: string, url: string, html: string}>
 */
function agend_apps_records_render_cards( string $type, int $template_id, array $records, array $opts = array() ): array {
	$whole_link   = ! isset( $opts['card_link_whole'] ) || (bool) $opts['card_link_whole'];
	$host_page_id = (int) ( $opts['host_page_id'] ?? 0 );
	$with_css     = ! empty( $opts['with_css'] );
	$cards        = array();

	Agend_Apps_Templates::ensure_styles( $template_id );

	foreach ( array_values( $records ) as $index => $record ) {
		if ( ! is_array( $record ) ) {
			continue;
		}
		$slug = isset( $record['slug'] ) ? (string) $record['slug'] : '';
		$url  = '' !== $slug ? Agend_Apps_Records_Pages::detail_url( $type, $slug, $host_page_id ) : '';

		$extra = array(
			'slug'         => $slug,
			'detail_url'   => $url,
			'index'        => $index,
			'in_card_link' => $whole_link,
			'host_page_id' => $host_page_id,
			'is_detail'    => false,
		);

		try {
			$inner = Agend_Apps_Templates::render( $template_id, $type, $record, $extra, $with_css && 0 === $index );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'Agend Apps: card render failed for template %d (%s): %s', $template_id, $slug, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			$inner = '';
		}

		$cards[] = array(
			'slug' => $slug,
			'url'  => $url,
			'html' => agend_apps_records_wrap_card( $type, $slug, $url, $inner, $whole_link ),
		);
	}

	return $cards;
}

/**
 * Wraps rendered card markup in its clickable shell.
 *
 * The shell carries `data-agend-slug` for the catalogue script's delegated
 * click handling. As one anchor the card also works without JavaScript and
 * with middle-click / open in new tab.
 *
 * @param string $type       'event' or 'course'.
 * @param string $slug       Record slug.
 * @param string $url        Detail URL.
 * @param string $inner      Rendered template HTML.
 * @param bool   $whole_link Whether the card is one anchor.
 * @return string
 */
function agend_apps_records_wrap_card( string $type, string $slug, string $url, string $inner, bool $whole_link ): string {
	$families = array( 'course' => 'agend-lms', 'listing' => 'agend-dir', 'event' => 'agend-ev' );
	$family   = $families[ $type ] ?? 'agend-ev';
	$classes = 'agend-card-link ' . $family . '-card-link';

	if ( $whole_link && '' !== $url ) {
		return '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '" data-agend-slug="' . esc_attr( $slug ) . '">' . $inner . '</a>';
	}

	return '<div class="' . esc_attr( $classes ) . '" data-agend-slug="' . esc_attr( $slug ) . '" data-agend-href="' . esc_url( $url ) . '">' . $inner . '</div>';
}
