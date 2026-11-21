<?php
/**
 * The wording a site uses for the price that is not the member price.
 *
 * Event cards, ticket lists and the event detail show a member price and a
 * second price for everyone else. Out of the box that second price is labelled
 * "Non-Members" and the note under the Register button speaks of "the
 * non-member rate". Some organisations call it something else ("Public",
 * "Standard", "Guests"), so both words are a per-site setting
 * (Settings > Agend Widgets > Event Pricing), with a filter each for a site
 * that sets them in code.
 *
 * A blank setting means "use the built-in wording", which stays translatable,
 * so a site that never touches the setting renders exactly what it did before.
 *
 * The same strings reach the browser-rendered events catalogue through
 * `window.agendApps.pricingLabels` ({@see agend_apps_output_config_js()}).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option name storing the label shown beside the non-member price ('' = default).
 *
 * @var string
 */
const AGEND_APPS_RECORDS_NON_MEMBER_LABEL_OPTION = 'agend_apps_non_member_label';

/**
 * Option name storing the word used for the non-member rate in notes ('' = default).
 *
 * @var string
 */
const AGEND_APPS_RECORDS_NON_MEMBER_RATE_LABEL_OPTION = 'agend_apps_non_member_rate_label';

/**
 * Option name storing the page a visitor who is not a member can join from
 * ('' = no join link).
 *
 * @var string
 */
const AGEND_APPS_RECORDS_MEMBER_JOIN_URL_OPTION = 'agend_apps_member_join_url';

/**
 * Longest label kept, so a stray paste cannot break the price row layout.
 *
 * @var int
 */
const AGEND_APPS_RECORDS_PRICING_LABEL_MAX = 40;

/**
 * Sanitises a submitted pricing label: plain text, trimmed, capped in length.
 *
 * @param mixed $value Raw submitted value.
 * @return string The clean label, or '' for "use the default".
 */
function agend_apps_records_sanitize_pricing_label( $value ): string {
	$value = is_scalar( $value ) ? (string) $value : '';
	$value = trim( sanitize_text_field( $value ) );
	if ( function_exists( 'mb_substr' ) ) {
		return trim( mb_substr( $value, 0, AGEND_APPS_RECORDS_PRICING_LABEL_MAX ) );
	}
	return trim( substr( $value, 0, AGEND_APPS_RECORDS_PRICING_LABEL_MAX ) );
}

/**
 * The built-in label for the non-member price row.
 *
 * @return string
 */
function agend_apps_records_default_non_member_label(): string {
	return __( 'Non-Members', 'agend-apps-core' );
}

/**
 * The built-in word for the non-member rate in notes.
 *
 * @return string
 */
function agend_apps_records_default_non_member_rate_label(): string {
	return __( 'non-member', 'agend-apps-core' );
}

/**
 * The label shown beside the non-member price on event cards and ticket lists.
 *
 * Returns raw text: escape it where it is printed.
 *
 * @return string
 */
function agend_apps_records_non_member_label(): string {
	$label = agend_apps_records_sanitize_pricing_label( get_option( AGEND_APPS_RECORDS_NON_MEMBER_LABEL_OPTION, '' ) );
	if ( '' === $label ) {
		$label = agend_apps_records_default_non_member_label();
	}

	/**
	 * Filters the label shown beside the non-member price ("Non-Members").
	 *
	 * @param string $label The label from the site setting, or the default.
	 */
	$filtered = apply_filters( 'agend_apps_non_member_label', $label );

	return ( is_string( $filtered ) && '' !== trim( $filtered ) ) ? trim( $filtered ) : $label;
}

/**
 * The word used for the non-member rate in the notes about pricing
 * ("The non-member rate is shown where available.").
 *
 * Returns raw text: escape it where it is printed.
 *
 * @return string
 */
function agend_apps_records_non_member_rate_label(): string {
	$label = agend_apps_records_sanitize_pricing_label( get_option( AGEND_APPS_RECORDS_NON_MEMBER_RATE_LABEL_OPTION, '' ) );
	if ( '' === $label ) {
		$label = agend_apps_records_default_non_member_rate_label();
	}

	/**
	 * Filters the word used for the non-member rate in pricing notes ("non-member").
	 *
	 * @param string $label The word from the site setting, or the default.
	 */
	$filtered = apply_filters( 'agend_apps_non_member_rate_label', $label );

	return ( is_string( $filtered ) && '' !== trim( $filtered ) ) ? trim( $filtered ) : $label;
}

/**
 * The note shown to a visitor who is not a member, about which rate they see.
 *
 * @return string Raw text: escape it where it is printed.
 */
function agend_apps_records_non_member_rate_note(): string {
	$rate = agend_apps_records_non_member_rate_label();
	if ( agend_apps_records_default_non_member_rate_label() === $rate ) {
		// The exact sentence sites have always had, so existing translations hold.
		return __( 'The non-member rate is shown where available. Final pricing is confirmed at checkout.', 'agend-apps-core' );
	}
	/* translators: %s: The site's word for the rate a non-member pays, e.g. "public". */
	return sprintf( __( 'The %s rate is shown where available. Final pricing is confirmed at checkout.', 'agend-apps-core' ), $rate );
}

/**
 * The note shown when every ticket is free for everyone.
 *
 * @return string Raw text: escape it where it is printed.
 */
function agend_apps_records_free_for_all_note(): string {
	if ( agend_apps_records_default_non_member_rate_label() === agend_apps_records_non_member_rate_label() ) {
		return __( 'Free for members and non-members.', 'agend-apps-core' );
	}
	// A custom word does not always read well as a plural ("members and
	// public"), so a site with its own wording gets a neutral sentence.
	return __( 'Free for everyone.', 'agend-apps-core' );
}

/**
 * The label of the "non-member price from" record field, which a card or
 * detail template can show beside the price.
 *
 * @return string Raw text: escape it where it is printed.
 */
function agend_apps_records_non_member_price_from_label(): string {
	$label = agend_apps_records_non_member_label();
	if ( agend_apps_records_default_non_member_label() === $label ) {
		return __( 'Non-member price from', 'agend-apps-core' );
	}
	/* translators: %s: The site's label for the non-member price, e.g. "Public". */
	return sprintf( __( '%s price from', 'agend-apps-core' ), $label );
}

/**
 * The pricing strings the browser-rendered events catalogue uses.
 *
 * Only a label the site has changed is sent. The script has always carried
 * its own English wording ("Non-Members" beside the English "Members"), so
 * leaving the defaults out keeps the catalogue exactly as it was on a site
 * that sets nothing, translated sites included.
 *
 * @return array<string, string>
 */
function agend_apps_records_pricing_labels_for_js(): array {
	$labels = array();

	$label = agend_apps_records_non_member_label();
	if ( agend_apps_records_default_non_member_label() !== $label ) {
		$labels['nonMember'] = $label;
	}

	$rate = agend_apps_records_non_member_rate_label();
	if ( agend_apps_records_default_non_member_rate_label() !== $rate ) {
		$labels['nonMemberRate'] = $rate;
		$labels['nonMemberNote'] = agend_apps_records_non_member_rate_note();
		$labels['freeForAll']    = agend_apps_records_free_for_all_note();
	}

	return $labels;
}

/**
 * Sanitises the submitted membership join page address: an http or https URL,
 * or '' for "no join link".
 *
 * @param mixed $value Raw submitted value.
 * @return string
 */
function agend_apps_records_sanitize_member_join_url( $value ): string {
	$value = is_scalar( $value ) ? trim( (string) $value ) : '';
	if ( '' === $value ) {
		return '';
	}
	$url    = (string) esc_url_raw( $value, array( 'http', 'https' ) );
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
		return '';
	}
	return $url;
}

/**
 * The page a signed-in visitor who is not a member is sent to from the note
 * "Member pricing applies to current Members." on an event's registration
 * form. Blank (the default) shows the note with no link.
 *
 * @return string An http(s) URL, or ''.
 */
function agend_apps_records_member_join_url(): string {
	$url = agend_apps_records_sanitize_member_join_url( get_option( AGEND_APPS_RECORDS_MEMBER_JOIN_URL_OPTION, '' ) );

	/**
	 * Filters the membership join page linked from the event registration form.
	 *
	 * @param string $url The URL from the site setting, or ''.
	 */
	$filtered = apply_filters( 'agend_apps_member_join_url', $url );

	return is_string( $filtered ) ? agend_apps_records_sanitize_member_join_url( $filtered ) : $url;
}
