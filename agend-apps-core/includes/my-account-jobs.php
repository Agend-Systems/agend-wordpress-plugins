<?php
/**
 * WooCommerce My Account "Jobs": where a member posts and manages their own
 * jobs, and buys job credits.
 *
 * - A "Jobs" item after "Orders" in the My Account menu, at `/my-account/jobs/`.
 * - The page shows the Elementor (or block) template chosen in Agend Widgets
 *   settings, so the association can lay it out with Agend Content
 *   Personalisation rules ("Has an unused job credit" from Agend Entitlement
 *   Mirror). With no template chosen it shows the Agend Member Jobs surface
 *   on its own.
 * - "Buy" buttons for the job credit products chosen in settings go through
 *   `?agend_jobs_buy=<product id>`: the product is put in the cart once and the
 *   member lands on checkout.
 *
 * Everything is inert without WooCommerce, job credits switched on, and an API
 * key that can use member job submissions.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite endpoint slug.
 *
 * @var string
 */
const AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT = 'jobs';

/**
 * Rewrite ruleset version; bump when the endpoint changes.
 *
 * @var string
 */
const AGEND_APPS_MY_ACCOUNT_JOBS_REWRITE_VERSION = '20261116-1';

/**
 * Option: the template the Jobs page shows, or 0 for the surface alone.
 *
 * @var string
 */
const AGEND_APPS_MY_ACCOUNT_JOBS_TEMPLATE_OPTION = 'agend_apps_my_account_jobs_template';

/**
 * Option: the job credit products members can buy, as a list of
 * `array{product_id: int, label: string}`, in button order.
 *
 * @var string
 */
const AGEND_APPS_MEMBER_JOBS_BUY_OPTION = 'agend_apps_member_jobs_buy_products';

/**
 * Query arg that puts a job credit product in the cart and opens checkout.
 *
 * @var string
 */
const AGEND_APPS_MEMBER_JOBS_BUY_ARG = 'agend_jobs_buy';

/**
 * Whether the My Account Jobs page is offered.
 *
 * @return bool
 */
function agend_apps_my_account_jobs_enabled(): bool {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'Agend_Apps_Jobs_Credits' ) || ! Agend_Apps_Jobs_Credits::enabled() ) {
		return false;
	}

	return ! function_exists( 'agend_apps_records_surface_available' ) || agend_apps_records_surface_available( 'member-jobs' );
}

/**
 * The My Account Jobs URL, or '' when the page is not offered.
 *
 * @return string
 */
function agend_apps_my_account_jobs_url(): string {
	if ( ! agend_apps_my_account_jobs_enabled() || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
		return '';
	}

	return (string) wc_get_account_endpoint_url( AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT );
}

/**
 * Registers the endpoint, as WooCommerce registers its own.
 */
function agend_apps_my_account_jobs_add_rewrite_endpoint(): void {
	if ( agend_apps_my_account_jobs_enabled() ) {
		add_rewrite_endpoint( AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT, EP_ROOT | EP_PAGES );
	}
}
add_action( 'init', 'agend_apps_my_account_jobs_add_rewrite_endpoint' );

/**
 * Flushes rewrite rules once per ruleset version, so an update deploy (which
 * runs no activation hook) does not leave the endpoint answering 404.
 */
function agend_apps_my_account_jobs_maybe_flush_rewrite_rules(): void {
	if ( ! agend_apps_my_account_jobs_enabled() || get_option( 'agend_apps_my_account_jobs_rewrite_version' ) === AGEND_APPS_MY_ACCOUNT_JOBS_REWRITE_VERSION ) {
		return;
	}

	flush_rewrite_rules();
	update_option( 'agend_apps_my_account_jobs_rewrite_version', AGEND_APPS_MY_ACCOUNT_JOBS_REWRITE_VERSION );
}
add_action( 'init', 'agend_apps_my_account_jobs_maybe_flush_rewrite_rules', 11 );

/**
 * Registers the query var with WooCommerce.
 *
 * @param string[] $vars WooCommerce's My Account query vars.
 * @return string[]
 */
function agend_apps_my_account_jobs_query_vars( array $vars ): array {
	if ( agend_apps_my_account_jobs_enabled() ) {
		$vars[ AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT ] = AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT;
	}

	return $vars;
}
add_filter( 'woocommerce_get_query_vars', 'agend_apps_my_account_jobs_query_vars' );

/**
 * Puts "Jobs" straight after "Orders" in the My Account menu, or before
 * "Log out" when the menu has no Orders item.
 *
 * @param array<string, string> $items Menu items keyed by endpoint.
 * @return array<string, string>
 */
function agend_apps_my_account_jobs_menu_item( array $items ): array {
	if ( ! agend_apps_my_account_jobs_enabled() ) {
		return $items;
	}

	$label  = __( 'Jobs', 'agend-apps-core' );
	$after  = isset( $items['orders'] ) ? 'orders' : '';
	$out    = array();
	$placed = false;

	foreach ( $items as $key => $item ) {
		if ( '' === $after && 'customer-logout' === $key && ! $placed ) {
			$out[ AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT ] = $label;
			$placed                                     = true;
		}

		$out[ $key ] = $item;

		if ( $key === $after ) {
			$out[ AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT ] = $label;
			$placed                                     = true;
		}
	}

	if ( ! $placed ) {
		$out[ AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT ] = $label;
	}

	return $out;
}
add_filter( 'woocommerce_account_menu_items', 'agend_apps_my_account_jobs_menu_item' );

/**
 * The endpoint's title.
 *
 * @param string $title Default title.
 * @return string
 */
function agend_apps_my_account_jobs_title( string $title ): string {
	return agend_apps_my_account_jobs_enabled() ? __( 'Jobs', 'agend-apps-core' ) : $title;
}
add_filter( 'woocommerce_endpoint_' . AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT . '_title', 'agend_apps_my_account_jobs_title' );

/**
 * The page content: the chosen template, or the surface on its own.
 *
 * @return string
 */
function agend_apps_my_account_jobs_render(): string {
	$template = absint( get_option( AGEND_APPS_MY_ACCOUNT_JOBS_TEMPLATE_OPTION, 0 ) );

	if ( $template > 0 && class_exists( 'Agend_Apps_Templates' ) && Agend_Apps_Templates::is_valid_template( $template ) ) {
		$html = Agend_Apps_Templates::render_plain( $template, true );

		if ( '' !== $html ) {
			return $html;
		}
	}

	if ( ! function_exists( 'agend_apps_records_render_member_jobs' ) ) {
		return '';
	}

	wp_enqueue_style( 'agend-apps-records-member-jobs' );
	wp_enqueue_script( 'agend-apps-records-member-jobs' );

	// The account page already has its own heading.
	return agend_apps_records_render_member_jobs( array( 'heading' => '' ) );
}

/**
 * Prints the page content.
 */
function agend_apps_my_account_jobs_content(): void {
	if ( agend_apps_my_account_jobs_enabled() ) {
		echo agend_apps_my_account_jobs_render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the renderer.
	}
}
add_action( 'woocommerce_account_' . AGEND_APPS_MY_ACCOUNT_JOBS_ENDPOINT . '_endpoint', 'agend_apps_my_account_jobs_content' );

/**
 * The job credit products configured for sale, as saved.
 *
 * @return array<int, array{product_id: int, label: string}>
 */
function agend_apps_member_jobs_buy_settings(): array {
	$rows = get_option( AGEND_APPS_MEMBER_JOBS_BUY_OPTION, array() );

	return agend_apps_member_jobs_sanitize_buy_products( is_array( $rows ) ? $rows : array() );
}

/**
 * Sanitizes the buy products setting: up to three rows with a product id,
 * each product once, in the order given.
 *
 * @param mixed $rows Submitted rows.
 * @return array<int, array{product_id: int, label: string}>
 */
function agend_apps_member_jobs_sanitize_buy_products( $rows ): array {
	$out  = array();
	$seen = array();

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$id = is_array( $row ) ? absint( $row['product_id'] ?? 0 ) : 0;

		if ( $id <= 0 || isset( $seen[ $id ] ) || count( $out ) >= 3 ) {
			continue;
		}

		$seen[ $id ] = true;
		$out[]       = array(
			'product_id' => $id,
			'label'      => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
		);
	}

	return $out;
}

/**
 * The Buy buttons to offer: each configured product that can be bought now,
 * with its label (or "Buy <product name>") and the URL that opens checkout.
 *
 * @return array<int, array{label: string, url: string}>
 */
function agend_apps_member_jobs_buy_options(): array {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return array();
	}

	$options = array();

	foreach ( agend_apps_member_jobs_buy_settings() as $row ) {
		$product = wc_get_product( $row['product_id'] );

		if ( ! $product || ! $product->is_purchasable() ) {
			continue;
		}

		$options[] = array(
			/* translators: %s: product name. */
			'label' => '' !== $row['label'] ? $row['label'] : sprintf( __( 'Buy %s', 'agend-apps-core' ), $product->get_name() ),
			'url'   => add_query_arg( AGEND_APPS_MEMBER_JOBS_BUY_ARG, $row['product_id'], home_url( '/' ) ),
		);
	}

	return $options;
}

/**
 * Handles a Buy button: puts the product in the cart unless it is already
 * there (so a reload or a second click never buys two), then opens checkout.
 * Only a configured job credit product is accepted.
 */
function agend_apps_member_jobs_handle_buy(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Adding a configured product to the visitor's own cart, as WooCommerce's add-to-cart links do.
	$id = isset( $_GET[ AGEND_APPS_MEMBER_JOBS_BUY_ARG ] ) ? absint( wp_unslash( $_GET[ AGEND_APPS_MEMBER_JOBS_BUY_ARG ] ) ) : 0;

	if ( $id <= 0 || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$allowed = wp_list_pluck( agend_apps_member_jobs_buy_settings(), 'product_id' );

	if ( ! in_array( $id, $allowed, true ) ) {
		return;
	}

	$in_cart = false;
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( (int) ( $item['product_id'] ?? 0 ) === $id ) {
			$in_cart = true;
			break;
		}
	}

	if ( ! $in_cart ) {
		WC()->cart->add_to_cart( $id, 1 );
	}

	wp_safe_redirect( wc_get_checkout_url() );
	exit;
}
add_action( 'template_redirect', 'agend_apps_member_jobs_handle_buy', 5 );
