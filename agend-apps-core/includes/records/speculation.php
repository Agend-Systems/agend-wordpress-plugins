<?php
/**
 * Hover prefetch for catalogue detail links.
 *
 * WordPress (6.8+) prefetches a link once a visitor starts to click it. An
 * Agend detail page is server-rendered from a gateway call, so most of its
 * wait happens before the click finishes; prefetching from the moment the
 * pointer rests on a card link (the "moderate" eagerness) lets that work run
 * while the visitor decides, and the page is usually ready when they click.
 *
 * Scoped to the links Agend renders (built-in cards, card-template links and
 * map pin details), so the rest of a site keeps WordPress's own default.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The CSS selector for the detail links that are prefetched on hover.
 *
 * @return string
 */
function agend_apps_records_detail_link_selector(): string {
	/**
	 * Filters which links are prefetched on hover. Return '' to turn hover
	 * prefetching off.
	 *
	 * @param string $selector A CSS selector list.
	 */
	return (string) apply_filters(
		'agend_apps_records_detail_link_selector',
		'a.agend-card-link, a.agend-dir-card[href], a.agend-map-popup__link'
	);
}

/**
 * Adds the hover prefetch rule to the page's speculation rules.
 *
 * @param WP_Speculation_Rules $rules The page's speculation rules.
 * @return void
 */
function agend_apps_records_add_detail_speculation_rule( $rules ): void {
	$selector = trim( agend_apps_records_detail_link_selector() );
	if ( '' === $selector || ! is_object( $rules ) || ! method_exists( $rules, 'add_rule' ) ) {
		return;
	}

	$rules->add_rule(
		'prefetch',
		'agend-detail-links',
		array(
			'source'    => 'document',
			'where'     => array( 'selector_matches' => $selector ),
			'eagerness' => 'moderate',
		)
	);
}
add_action( 'wp_load_speculation_rules', 'agend_apps_records_add_detail_speculation_rule' );
