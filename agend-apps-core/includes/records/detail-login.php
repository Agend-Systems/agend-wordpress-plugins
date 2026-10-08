<?php
/**
 * Sign-in requirement for directory listing detail pages.
 *
 * The Directory Catalogue's "Require sign-in to view details" switch lives on
 * a widget (or block) instance, but a request for a listing detail URL
 * (/{page}/listing/{slug}/) has to be decided before anything renders, and
 * server-rendered detail pages never render the widget at all. So the switch
 * is mirrored, on save, to a post meta flag on the page that holds the
 * catalogue, and a request reads that one flag.
 *
 * Each page builder mirrors its own source into the flag: this file owns the
 * block editor (`save_post_page` + `parse_blocks`); Agend Elementor owns
 * Elementor, through agend_apps_records_directory_set_login_source(). Core
 * never parses another builder's data. The flag holds the list of sources that
 * have the switch on, so one builder saving a page never clears what another
 * recorded, and a page with several catalogues is flagged when ANY one of them
 * has the switch on.
 *
 * Known blind spot: only a catalogue placed in the page's own content is seen.
 * One that lives only inside a saved template, an Elementor theme-builder
 * template, a synced pattern or a block theme template is not, and the switch
 * has no effect on direct detail URLs for that page.
 *
 * Loaded unconditionally from the plugin bootstrap, like pages.php, because
 * the redirect must not depend on the gateway or on a page builder being up.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The post meta flag, private to Agend. Any non-empty value means the page's
 * directory detail pages require a signed-in visitor. Written as the list of
 * builder sources that have the switch on (see
 * agend_apps_records_directory_set_login_source()); an empty or absent value
 * means they do not.
 */
const AGEND_APPS_RECORDS_DIRECTORY_LOGIN_META = '_agend_apps_directory_require_login';

/**
 * The Directory Catalogue's block name, which the block-editor mirror looks for.
 */
const AGEND_APPS_RECORDS_DIRECTORY_BLOCK = 'agend-apps/directory-catalogue';

/**
 * Option recording the backfill version that last ran. Bump
 * AGEND_APPS_RECORDS_LOGIN_BACKFILL_VERSION whenever what is mirrored changes,
 * so the backfill runs once more on the first request after that update.
 */
const AGEND_APPS_RECORDS_LOGIN_BACKFILL_OPTION = 'agend_apps_records_login_backfill';

/**
 * The current backfill version.
 */
const AGEND_APPS_RECORDS_LOGIN_BACKFILL_VERSION = '1';

/**
 * Whether a page's directory detail pages require a signed-in visitor.
 *
 * The one place a request reads the setting. Builder-agnostic: it reads the
 * mirrored flag, never a builder's own data.
 *
 * @param int $page_id The page holding the catalogue.
 * @return bool
 */
function agend_apps_records_directory_detail_requires_login( int $page_id ): bool {
	if ( $page_id <= 0 ) {
		return false;
	}

	return ! empty( get_post_meta( $page_id, AGEND_APPS_RECORDS_DIRECTORY_LOGIN_META, true ) );
}

/**
 * Records, for one builder source, whether a page has the switch on.
 *
 * A page builder calls this from its own save hook with a short slug of its
 * own (`blocks`, `elementor`). The flag is the list of sources that have the
 * switch on: turning one source off removes only that source, and the meta is
 * deleted once no source is left.
 *
 * @param int    $post_id The page that was saved.
 * @param string $source  The builder's slug.
 * @param bool   $on      Whether that builder's content has a catalogue with the switch on.
 */
function agend_apps_records_directory_set_login_source( int $post_id, string $source, bool $on ): void {
	$source = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $source ) );
	if ( $post_id <= 0 || '' === $source ) {
		return;
	}

	$stored  = get_post_meta( $post_id, AGEND_APPS_RECORDS_DIRECTORY_LOGIN_META, true );
	$sources = is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	$has     = in_array( $source, $sources, true );

	if ( $on === $has ) {
		return;
	}

	$sources = $on ? array_merge( $sources, array( $source ) ) : array_values( array_diff( $sources, array( $source ) ) );

	if ( array() === $sources ) {
		delete_post_meta( $post_id, AGEND_APPS_RECORDS_DIRECTORY_LOGIN_META );
		return;
	}

	update_post_meta( $post_id, AGEND_APPS_RECORDS_DIRECTORY_LOGIN_META, $sources );
}

/**
 * Whether a parsed block tree holds a Directory Catalogue with the switch on.
 *
 * Any one catalogue with it on is enough, at any depth.
 *
 * @param array $blocks Blocks as parse_blocks() returns them.
 * @return bool
 */
function agend_apps_records_blocks_require_login( array $blocks ): bool {
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		if ( AGEND_APPS_RECORDS_DIRECTORY_BLOCK === ( $block['blockName'] ?? null ) && ! empty( $block['attrs']['require_login_for_detail'] ) ) {
			return true;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && agend_apps_records_blocks_require_login( $block['innerBlocks'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Mirrors the block editor's side of the switch to the page's flag.
 *
 * Runs on every page save, so a catalogue removed from the page, or switched
 * off, clears the `blocks` source. It only ever touches its own source: a
 * Quick Edit of an Elementor page runs this too, and must not clear what
 * Elementor recorded.
 *
 * @param int          $post_id The saved page.
 * @param WP_Post|null $post    The saved page, when the hook supplies it.
 */
function agend_apps_records_directory_mirror_blocks_flag( int $post_id, $post = null ): void {
	if ( ! ( $post instanceof WP_Post ) ) {
		$post = get_post( $post_id );
	}

	// The detail rewrite rule resolves a page (`pagename=`), so only a page
	// can host a directory detail URL.
	if ( ! ( $post instanceof WP_Post ) || 'page' !== $post->post_type ) {
		return;
	}

	$content = (string) $post->post_content;

	// Most pages hold no catalogue: skip parsing them.
	$on = false !== strpos( $content, 'wp:' . AGEND_APPS_RECORDS_DIRECTORY_BLOCK )
		&& agend_apps_records_blocks_require_login( parse_blocks( $content ) );

	agend_apps_records_directory_set_login_source( $post_id, 'blocks', $on );
}
add_action( 'save_post_page', 'agend_apps_records_directory_mirror_blocks_flag', 10, 2 );

/**
 * Re-derives a page's flag from its saved content, for every builder.
 *
 * The blocks source is derived here; any other builder listens for the action
 * and records its own, so core never reads another builder's data. Used when a
 * page's flag may be missing: on upgrade, and when the dedicated directory
 * page changes.
 *
 * @param int $page_id The page to re-derive.
 */
function agend_apps_records_directory_backfill_login_flag( int $page_id ): void {
	$post = $page_id > 0 ? get_post( $page_id ) : null;
	if ( ! ( $post instanceof WP_Post ) || 'page' !== $post->post_type ) {
		return;
	}

	agend_apps_records_directory_mirror_blocks_flag( $page_id, $post );

	/**
	 * Fires when a page's directory sign-in flag is being re-derived from its
	 * saved content, so a page builder can record its own source with
	 * agend_apps_records_directory_set_login_source().
	 *
	 * @param int     $page_id The page.
	 * @param WP_Post $post    The page.
	 */
	do_action( 'agend_apps_records_directory_backfill_login_flag', $page_id, $post );
}

/**
 * One-time backfill of the dedicated directory page's flag.
 *
 * A page saved before this feature shipped has no flag, so a request could not
 * tell whether its switch is on. This re-derives it once per backfill version,
 * on the first request after an update (which does not run the activation
 * hook), for the dedicated directory page. Pages saved afterwards mirror
 * themselves on save, and a page made the dedicated page later is backfilled
 * when it is chosen.
 */
function agend_apps_records_directory_maybe_backfill_login_flag(): void {
	if ( AGEND_APPS_RECORDS_LOGIN_BACKFILL_VERSION === get_option( AGEND_APPS_RECORDS_LOGIN_BACKFILL_OPTION ) ) {
		return;
	}

	agend_apps_records_directory_backfill_login_flag( Agend_Apps_Records_Pages::page_id( 'listing' ) );

	update_option( AGEND_APPS_RECORDS_LOGIN_BACKFILL_OPTION, AGEND_APPS_RECORDS_LOGIN_BACKFILL_VERSION );
}
add_action( 'init', 'agend_apps_records_directory_maybe_backfill_login_flag', 12 );

/**
 * Backfills a page newly chosen as the dedicated directory page (first time
 * the option is saved).
 *
 * @param string $option The option name.
 * @param mixed  $value  The new page id.
 */
function agend_apps_records_directory_backfill_on_page_added( $option, $value ): void {
	agend_apps_records_directory_backfill_login_flag( absint( $value ) );
}
add_action( 'add_option_' . AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION, 'agend_apps_records_directory_backfill_on_page_added', 10, 2 );

/**
 * Backfills a page newly chosen as the dedicated directory page.
 *
 * @param mixed $old_value The previous page id.
 * @param mixed $value     The new page id.
 */
function agend_apps_records_directory_backfill_on_page_updated( $old_value, $value ): void {
	agend_apps_records_directory_backfill_login_flag( absint( $value ) );
}
add_action( 'update_option_' . AGEND_APPS_RECORDS_DIRECTORY_PAGE_OPTION, 'agend_apps_records_directory_backfill_on_page_updated', 10, 2 );

/**
 * Where a signed-out visitor is sent for the request being served, or ''.
 *
 * The decision behind the `wp` hook below, free of the redirect and `exit` so
 * it can be tested. Empty unless every one of these holds: a front-end GET for
 * the main query; a listing slug on the request, on either the pretty
 * (`agend_dir_listing`) or legacy (`agend_listing`) query var; a visitor who is
 * not signed in; and a flag on the page that hosts the catalogue.
 *
 * The host page is the dedicated directory page when one is configured, the
 * queried page otherwise, the same page the detail URL is built against.
 *
 * @return string The sign-in URL, returning to the detail afterwards, or ''.
 */
function agend_apps_records_directory_login_redirect_target(): string {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() ) {
		return '';
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return '';
	}
	if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		return '';
	}
	if ( ! is_main_query() || is_user_logged_in() ) {
		return '';
	}

	$slug = sanitize_title( (string) get_query_var( 'agend_dir_listing' ) );
	if ( '' === $slug ) {
		$slug = sanitize_title( (string) get_query_var( 'agend_listing' ) );
	}
	if ( '' === $slug ) {
		return '';
	}

	$page_id = Agend_Apps_Records_Pages::page_id( 'listing' );
	if ( 0 === $page_id ) {
		$page_id = (int) get_queried_object_id();
	}
	if ( ! agend_apps_records_directory_detail_requires_login( $page_id ) ) {
		return '';
	}

	return Agend_Apps_Records_Pages::login_target( Agend_Apps_Records_Pages::detail_url( 'listing', $slug, $page_id ), true );
}

/**
 * Sends a signed-out visitor who asked for a gated listing detail to sign in.
 *
 * Runs on `wp` at priority 4, ahead of the server-rendered detail swap at
 * priority 5 (ssr-detail.php), so the redirect happens before the detail is
 * fetched from the gateway or any output starts. A 302, not a 301: the answer
 * depends on who is asking.
 */
function agend_apps_records_maybe_redirect_directory_detail_to_login(): void {
	$target = agend_apps_records_directory_login_redirect_target();
	if ( '' === $target ) {
		return;
	}

	nocache_headers();

	// wp_redirect(), not wp_safe_redirect(): the target is wp_login_url(),
	// which a site's SSO or custom-login plugin may point at another host,
	// and wp_safe_redirect() would swap that for the admin URL. The return
	// address inside it is built from this site's own permalink and a
	// sanitised slug, never from request input.
	wp_redirect( $target, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}
add_action( 'wp', 'agend_apps_records_maybe_redirect_directory_detail_to_login', 4 );
