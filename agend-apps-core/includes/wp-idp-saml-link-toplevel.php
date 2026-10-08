<?php
/**
 * Top-level (non-framed) SAML link triggers: link a member during sign-in, and
 * before a directory page is built.
 *
 * `includes/wp-idp-saml-link.php` links a signed-in member in the background:
 * a hidden iframe fetches the sso-url endpoint AFTER the page has loaded. That
 * is invisible to the member but it is late. A page that reads the member's
 * restricted data on the server (the directory's listing detail and its first
 * page of cards) has already been built, with no member bearer, by the time
 * the link exists, and nothing refreshes it afterwards: the member sees the
 * logged-out placeholders ("Sign in to view") on their very first visit.
 *
 * Both triggers here run the SAME IdP-initiated round trip at the top level
 * instead, so the browser comes back linked, to the page it asked for:
 *
 * B. Sign-in and registration ({@see agend_apps_saml_link_signin_redirect()}).
 *    The post-sign-in redirect is rewritten to a same-origin "start" step
 *    (`/?agend_apps_saml_link_start=1&redirect_to=...`). The rewrite must NOT
 *    mint the IdP URL itself: its nonce is bound to the WordPress session, and
 *    while a sign-in request is still running the NEW auth cookie is not in
 *    `$_COOKIE` yet, so a nonce minted there would be bound to the wrong
 *    session and fail verification at the IdP. The start step
 *    ({@see agend_apps_saml_link_start_decision()}) runs on the NEXT request,
 *    when the cookie is present and the member is authenticated, and only then
 *    calls {@see agend_apps_saml_link_issue_url()} (non-framed, returning to the
 *    original destination).
 *
 * A. Pre-render ({@see agend_apps_saml_link_prerender_target()}). A signed-in,
 *    unlinked member's front-end GET of the directory page or a listing detail
 *    is redirected through the round trip, returning to the same URL, BEFORE
 *    any directory data is fetched. It runs on `wp` at priority 3, ahead of the
 *    sign-in gate for gated listing detail (`wp` 4, `includes/records/detail-
 *    login.php`, which only concerns signed-out visitors) and the server-
 *    rendered detail swap and its gateway call (`wp` 5). It is the safety net
 *    for everything B cannot see: an AJAX or JS sign-in, a cached session, a
 *    member who arrives already signed in and unlinked.
 *
 * Neither adds bookkeeping of its own. Both reuse
 * {@see agend_apps_saml_link_eligibility()}, the recorded link state, the
 * throttle, and {@see agend_apps_saml_link_issue_url()}, which counts the
 * attempt and stamps a short-lived marker (`toplevel_at` / `toplevel_via`) in
 * the same state write. That marker, the `completed` flag the done handler
 * sets, and the throttle are the loop guard
 * ({@see agend_apps_saml_link_toplevel_hold()}): a member who has just come
 * back from a top-level round trip, whether it linked them or failed, is shown
 * the page rather than sent round again, and the background iframe path carries
 * on exactly as before.
 *
 * Both are inert unless the member is eligible, which already means `wordpress`
 * sign-in mode, the SAML mechanism and the background link toggle being on.
 * Each has its own toggle on Settings > Identity and SSO
 * ({@see Agend_Apps_Settings::signin_link_enabled()},
 * {@see Agend_Apps_Settings::prerender_link_enabled()}), default on. Every hook
 * wraps its work in `try`/`catch`: a defect here must never break a page view
 * or a sign-in.
 *
 * Loaded only in `wordpress` sign-in mode, after `wp-idp-saml-link.php`.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The scheme, host and port of this site, with no path: what a root-relative
 * request URI is appended to. Read from `home_url()` rather than the request's
 * `Host` header, which a client controls.
 *
 * @return string For example `https://example.com`, or '' when it cannot be read.
 */
function agend_apps_saml_link_origin(): string {
	$parts = wp_parse_url( home_url( '/' ) );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return '';
	}

	return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
}

/**
 * The URL of the request being served, on this site's own origin.
 *
 * Built from `REQUEST_URI` (which already carries any subdirectory the site
 * lives in) appended to the origin, not from `home_url( REQUEST_URI )`, which
 * would repeat that subdirectory.
 *
 * @return string
 */
function agend_apps_saml_link_current_url(): string {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- a server-set path/query; it is only ever passed on as a redirect target, and every redirect target is validated with wp_validate_redirect() before use.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

	if ( '' === $uri || '/' !== $uri[0] ) {
		$uri = '/' . $uri;
	}

	return esc_url_raw( agend_apps_saml_link_origin() . $uri );
}

/**
 * A sign-in or return destination validated against this site, as an absolute
 * URL, or '' when it is not usable.
 *
 * `wp_validate_redirect()` is the open-redirect defence: an off-site host, a
 * non-http scheme or a malformed URL comes back as `$fallback`. Two more
 * refusals on top of it: a destination that is itself a start-step or done-URL
 * (so a crafted `redirect_to` cannot chain the round trip into itself), and any
 * result that is not an http(s) URL or a root-relative path, which the minimal
 * test stub of `wp_validate_redirect()` would otherwise let through.
 *
 * @param string $raw      The destination as received. Raw/unvalidated.
 * @param string $fallback What to return when the destination is not usable.
 * @return string An absolute on-site URL, or `$fallback`.
 */
function agend_apps_saml_link_valid_destination( string $raw, string $fallback = '' ): string {
	$raw = trim( $raw );

	if ( '' === $raw ) {
		return $fallback;
	}

	$validated = wp_validate_redirect( $raw, '' );

	if ( '' === $validated ) {
		return $fallback;
	}

	if ( false !== strpos( $validated, AGEND_APPS_SAML_LINK_START_FLAG ) || false !== strpos( $validated, AGEND_APPS_SAML_LINK_DONE_FLAG ) ) {
		return $fallback;
	}

	if ( 0 === strpos( $validated, '/' ) && 0 !== strpos( $validated, '//' ) ) {
		$validated = agend_apps_saml_link_origin() . $validated;
	}

	if ( 1 !== preg_match( '#^https?://#i', $validated ) ) {
		return $fallback;
	}

	return $validated;
}

/**
 * Whether a destination is inside wp-admin or is the login screen itself.
 *
 * A member heading for wp-admin is not detoured: nothing there builds
 * directory data, and the admin footer trigger
 * ({@see agend_apps_saml_link_render_admin_placeholder()}) already links them
 * on the first admin page.
 *
 * @param string $url A destination URL.
 * @return bool
 */
function agend_apps_saml_link_destination_is_admin( string $url ): bool {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );

	return false !== strpos( $path, '/wp-admin' ) || false !== strpos( $path, 'wp-login.php' );
}

/**
 * Whether a destination is a page a member must not be detoured on the way to:
 * the WooCommerce cart, checkout (and its endpoints), or an account page that
 * carries a form or endpoint.
 *
 * The same standard as {@see agend_apps_saml_link_surface_blocked()}, applied
 * to a URL rather than to the current request: a member who signs in
 * mid-purchase or mid-account-form must not have a round trip inserted in
 * front of that page. The one account page allowed is the read-only My Account
 * dashboard (the account page with no endpoint). Matched on the page's path
 * and, for a plain-permalink site, on `?page_id=`; an account URL carrying any
 * query key other than `page_id` and common tracking keys is treated as an
 * endpoint. With WooCommerce absent nothing is blocked.
 *
 * @param string $url A destination URL.
 * @return bool
 */
function agend_apps_saml_link_destination_blocked( string $url ): bool {
	$blocked = false;

	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) ? rtrim( (string) ( $parts['path'] ?? '' ), '/' ) : '';
		$query = array();

		parse_str( is_array( $parts ) ? (string) ( $parts['query'] ?? '' ) : '', $query );

		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$page_path = rtrim( (string) wp_parse_url( (string) wc_get_page_permalink( $page ), PHP_URL_PATH ), '/' );
			$page_id   = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $page ) : 0;

			$on_page_by_path = '' !== $page_path && 0 === strpos( $path . '/', $page_path . '/' );
			$on_page_by_id   = $page_id > 0 && isset( $query['page_id'] ) && (int) $query['page_id'] === $page_id;

			if ( ! $on_page_by_path && ! $on_page_by_id ) {
				continue;
			}

			if ( 'myaccount' !== $page ) {
				$blocked = true;
				break;
			}

			// The account dashboard is the one page allowed. Anything below its
			// path, or any query key beyond the page id and tracking noise, is an
			// endpoint (edit-account, edit-address, orders, and the rest).
			if ( $on_page_by_path && rtrim( $path, '/' ) !== $page_path ) {
				$blocked = true;
				break;
			}

			foreach ( array_keys( $query ) as $key ) {
				if ( 'page_id' !== $key && 1 !== preg_match( '/^(utm_|fbclid$|gclid$)/', (string) $key ) ) {
					$blocked = true;
					break 2;
				}
			}
		}
	}

	/**
	 * Filters whether a sign-in destination is one a member must not be
	 * detoured on the way to (so the sign-in link step is skipped for it).
	 *
	 * @param bool   $blocked Whether the destination is blocked.
	 * @param string $url     The validated, absolute destination URL.
	 */
	return (bool) apply_filters( 'agend_apps_saml_link_signin_destination_blocked', $blocked, $url );
}

/**
 * The loop guard shared by the sign-in and pre-render triggers: why this
 * member must NOT be sent round a top-level trip right now, or '' when they may
 * be. Pure read. Assumes the caller already found the member eligible
 * ({@see agend_apps_saml_link_eligibility()}), which covers linked, the attempt
 * cap, the mode, the mechanism and the background toggle.
 *
 * In order:
 * 1. `round_trip_done`: the done URL has been reached before
 *    (`completed`). A trip has already come back; if the member is still not
 *    linked the gateway did not link them, and repeating it will not help.
 * 2. `recent_round_trip`: a top-level trip was issued inside
 *    {@see AGEND_APPS_SAML_LINK_TOPLEVEL_WINDOW}. This is what covers a failed
 *    trip that never came back: the member returns to the page and is shown it
 *    instead of being bounced round again.
 * 3. `throttled`: the existing per-state backoff
 *    ({@see agend_apps_wp_idp_link_is_throttled()}), including a background
 *    attempt made moments ago.
 *
 * @param int    $user_id WordPress user id.
 * @param string $via     `signin` or `prerender`, used as the reason prefix.
 * @return string A recordable reason (for example `prerender_throttled`), or ''.
 */
function agend_apps_saml_link_toplevel_hold( int $user_id, string $via ): string {
	$stored = agend_apps_wp_idp_link_state( $user_id );

	if ( $stored['completed'] ) {
		return $via . '_round_trip_done';
	}

	if ( $stored['toplevel_at'] > 0 && ( time() - $stored['toplevel_at'] ) < AGEND_APPS_SAML_LINK_TOPLEVEL_WINDOW ) {
		return $via . '_recent_round_trip';
	}

	if ( agend_apps_wp_idp_link_is_throttled( $stored ) ) {
		return $via . '_throttled';
	}

	return '';
}

/**
 * Records why a top-level round trip was skipped for an eligible member, for
 * the diagnostics panel. Writes only when the reason differs from the one on
 * record, so a member who keeps hitting the same hold costs one write, not one
 * per page view.
 *
 * @param int    $user_id WordPress user id.
 * @param string $reason  A reason such as `signin_blocked_destination` or `prerender_throttled`.
 */
function agend_apps_saml_link_record_toplevel_skip( int $user_id, string $reason ): void {
	if ( 0 === $user_id || '' === $reason ) {
		return;
	}

	$stored = get_user_meta( $user_id, AGEND_APPS_SAML_LINK_TOPLEVEL_SKIP_META, true );

	if ( is_array( $stored ) && isset( $stored['reason'] ) && $reason === $stored['reason'] ) {
		return;
	}

	update_user_meta(
		$user_id,
		AGEND_APPS_SAML_LINK_TOPLEVEL_SKIP_META,
		array(
			'reason'    => $reason,
			'timestamp' => time(),
		)
	);
}

/**
 * Whether the current request is an ordinary top-level browser navigation, as
 * far as the browser says so.
 *
 * The pre-render trigger must not fire for a prefetch or prerender (the member
 * has not asked for the page, and the trip would burn an attempt), nor for a
 * request made into an iframe, an `<object>` or by script (a top-level
 * redirect through the identity provider cannot happen inside one). Browsers
 * that send no `Sec-Fetch-*` headers are treated as navigations.
 *
 * @return bool
 */
function agend_apps_saml_link_navigation_request(): bool {
	$read = static function ( string $key ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- a request header compared against fixed strings, never output or stored.
		return isset( $_SERVER[ $key ] ) ? strtolower( trim( (string) $_SERVER[ $key ] ) ) : '';
	};

	$dest = $read( 'HTTP_SEC_FETCH_DEST' );
	$mode = $read( 'HTTP_SEC_FETCH_MODE' );

	if ( '' !== $dest && 'document' !== $dest ) {
		return false;
	}

	if ( '' !== $mode && 'navigate' !== $mode ) {
		return false;
	}

	foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE' ) as $header ) {
		$purpose = $read( $header );

		if ( '' !== $purpose && ( false !== strpos( $purpose, 'prefetch' ) || false !== strpos( $purpose, 'prerender' ) || false !== strpos( $purpose, 'preview' ) ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Whether the request is an editor or preview render rather than a member
 * viewing the page: a post preview, the Customizer, or a page builder's
 * preview frame. A redirect through the identity provider there would break
 * the editor, and the page is not being viewed as a member would.
 *
 * @return bool
 */
function agend_apps_saml_link_preview_request(): bool {
	if ( function_exists( 'is_preview' ) && is_preview() ) {
		return true;
	}

	if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key PRESENCE only, to detect an editor preview frame; never output or stored.
	foreach ( array( 'elementor-preview', 'preview', 'customize_changeset_uuid', 'fl_builder', 'et_fb', 'ct_builder', 'bricks' ) as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ $key ] ) ) {
			return true;
		}
	}

	return false;
}

// ---------------------------------------------------------------------------
// B. Link during sign-in and registration
// ---------------------------------------------------------------------------

/**
 * The same-origin start-step URL: where a sign-in or registration redirect is
 * sent instead of its destination. Carries the validated destination and
 * NOTHING session-bound (no nonce), because it is minted while the new auth
 * cookie is not yet usable; see the file docblock.
 *
 * `redirect_to` is `rawurlencode()`'d here, before `add_query_arg()`, for the
 * reason {@see agend_apps_saml_link_done_url()} documents.
 *
 * @param string $destination A validated absolute on-site destination.
 * @return string
 */
function agend_apps_saml_link_start_url( string $destination ): string {
	return add_query_arg(
		array(
			AGEND_APPS_SAML_LINK_START_FLAG => '1',
			'redirect_to'                   => rawurlencode( $destination ),
		),
		home_url( '/' )
	);
}

/**
 * Rewrites a post-sign-in destination so the member is linked before the first
 * page they see, or returns it untouched.
 *
 * Called by every sign-in or registration redirect filter below, and available
 * to any other sign-in code that ends in a redirect (a custom AJAX login can
 * return the result for its script to navigate to). Rewrites only when ALL of:
 * the sign-in toggle is on; the destination validates against this site
 * ({@see agend_apps_saml_link_valid_destination()}); the member is eligible;
 * the destination is not wp-admin and not a cart, checkout or account form
 * page; and the loop guard holds nothing back. An eligible member held back by
 * the destination or the loop guard has the reason recorded for diagnostics.
 *
 * Mints nothing and writes no link state: the attempt is counted by the start
 * step, on the request that actually issues the URL.
 *
 * @param string $redirect_to The destination WordPress or WooCommerce chose. Raw/unvalidated.
 * @param int    $user_id     The member who has just signed in (0 = unknown).
 * @return string The start-step URL, or `$redirect_to` unchanged.
 */
function agend_apps_saml_link_signin_redirect( string $redirect_to, int $user_id ): string {
	try {
		if ( 0 === $user_id || ! Agend_Apps_Settings::signin_link_enabled() ) {
			return $redirect_to;
		}

		$destination = agend_apps_saml_link_valid_destination( $redirect_to );

		if ( '' === $destination ) {
			return $redirect_to;
		}

		if ( ! agend_apps_saml_link_eligibility( $user_id )['eligible'] ) {
			return $redirect_to;
		}

		if ( agend_apps_saml_link_destination_is_admin( $destination ) ) {
			agend_apps_saml_link_record_toplevel_skip( $user_id, 'signin_admin_destination' );

			return $redirect_to;
		}

		if ( agend_apps_saml_link_destination_blocked( $destination ) ) {
			agend_apps_saml_link_record_toplevel_skip( $user_id, 'signin_blocked_destination' );

			return $redirect_to;
		}

		$hold = agend_apps_saml_link_toplevel_hold( $user_id, AGEND_APPS_SAML_LINK_VIA_SIGNIN );

		if ( '' !== $hold ) {
			agend_apps_saml_link_record_toplevel_skip( $user_id, $hold );

			return $redirect_to;
		}

		return agend_apps_saml_link_start_url( $destination );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link sign-in redirect failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		return $redirect_to;
	}
}

/**
 * `login_redirect` filter (wp-login.php, and any code that calls
 * `apply_filters( 'login_redirect', ... )`). Registered late so a site's own
 * redirect rules have already chosen the destination this wraps.
 *
 * @param mixed $redirect_to           The destination.
 * @param mixed $requested_redirect_to The destination the sign-in form asked for.
 * @param mixed $user                  The signed-in `WP_User`, or a `WP_Error`.
 * @return mixed
 */
function agend_apps_saml_link_filter_login_redirect( $redirect_to, $requested_redirect_to = '', $user = null ) {
	unset( $requested_redirect_to );

	if ( ! is_string( $redirect_to ) || ! is_object( $user ) || ! isset( $user->ID ) ) {
		return $redirect_to;
	}

	return agend_apps_saml_link_signin_redirect( $redirect_to, (int) $user->ID );
}

/**
 * `woocommerce_login_redirect` filter (the My Account and checkout login
 * forms).
 *
 * @param mixed $redirect The destination.
 * @param mixed $user     The signed-in `WP_User`.
 * @return mixed
 */
function agend_apps_saml_link_filter_woocommerce_login_redirect( $redirect, $user = null ) {
	if ( ! is_string( $redirect ) || ! is_object( $user ) || ! isset( $user->ID ) ) {
		return $redirect;
	}

	return agend_apps_saml_link_signin_redirect( $redirect, (int) $user->ID );
}

/**
 * `woocommerce_registration_redirect` filter (the My Account and checkout
 * registration forms). WooCommerce passes no user, but signs the new customer
 * in (`wp_set_current_user()`) before it redirects, so the current user is the
 * member; when it did not sign them in (registration needs confirmation first)
 * there is no one to link and the destination is left alone.
 *
 * @param mixed $redirect The destination.
 * @return mixed
 */
function agend_apps_saml_link_filter_woocommerce_registration_redirect( $redirect ) {
	if ( ! is_string( $redirect ) ) {
		return $redirect;
	}

	return agend_apps_saml_link_signin_redirect( $redirect, get_current_user_id() );
}

add_filter( 'login_redirect', 'agend_apps_saml_link_filter_login_redirect', 99, 3 );
add_filter( 'woocommerce_login_redirect', 'agend_apps_saml_link_filter_woocommerce_login_redirect', 99, 2 );
add_filter( 'woocommerce_registration_redirect', 'agend_apps_saml_link_filter_woocommerce_registration_redirect', 99, 1 );

/**
 * The start step's pure decision: where a request carrying the start flag is
 * sent, now that the member is authenticated.
 *
 * `skip` when the request is not a start request. Otherwise ALWAYS `redirect`:
 * a member who followed a sign-in redirect must end up at their destination
 * whatever happens here. The target is the IdP-initiated URL (non-framed,
 * returning to the destination, counted as a `signin` attempt) when the member
 * is signed in, the toggle is on, they are eligible, the destination is neither
 * wp-admin nor a blocked page, and the loop guard holds nothing back; the
 * validated destination otherwise, with `reason` saying why (and, for the
 * reasons worth recording, the diagnostics record updated).
 *
 * The destination arrives in the query string and is attacker-controlled: it is
 * validated against this site and falls back to the home page.
 *
 * Checks eligibility itself rather than trusting the redirect filter that sent
 * the member here: state may have moved in between (the member is now linked,
 * or throttled by a background attempt), and this is the request that mints
 * the nonce, so it is the one that has to be right.
 *
 * @param int   $user_id Current WordPress user id (0 = signed out).
 * @param array $query   The relevant `$_GET` values: the start flag and `redirect_to`. Raw/unvalidated.
 * @return array{action: string, url: string, reason: string}
 */
function agend_apps_saml_link_start_decision( int $user_id, array $query ): array {
	if ( ! isset( $query[ AGEND_APPS_SAML_LINK_START_FLAG ] ) || '1' !== (string) $query[ AGEND_APPS_SAML_LINK_START_FLAG ] ) {
		return array(
			'action' => 'skip',
			'url'    => '',
			'reason' => '',
		);
	}

	$destination = agend_apps_saml_link_valid_destination( isset( $query['redirect_to'] ) ? (string) $query['redirect_to'] : '', home_url( '/' ) );

	$go_to_destination = static function ( string $reason ) use ( $destination ): array {
		return array(
			'action' => 'redirect',
			'url'    => $destination,
			'reason' => $reason,
		);
	};

	if ( 0 === $user_id ) {
		return $go_to_destination( 'signed_out' );
	}

	if ( ! Agend_Apps_Settings::signin_link_enabled() ) {
		return $go_to_destination( 'signin_link_disabled' );
	}

	$eligibility = agend_apps_saml_link_eligibility( $user_id );

	if ( ! $eligibility['eligible'] ) {
		return $go_to_destination( $eligibility['reason'] );
	}

	if ( agend_apps_saml_link_destination_is_admin( $destination ) ) {
		agend_apps_saml_link_record_toplevel_skip( $user_id, 'signin_admin_destination' );

		return $go_to_destination( 'signin_admin_destination' );
	}

	if ( agend_apps_saml_link_destination_blocked( $destination ) ) {
		agend_apps_saml_link_record_toplevel_skip( $user_id, 'signin_blocked_destination' );

		return $go_to_destination( 'signin_blocked_destination' );
	}

	$hold = agend_apps_saml_link_toplevel_hold( $user_id, AGEND_APPS_SAML_LINK_VIA_SIGNIN );

	if ( '' !== $hold ) {
		agend_apps_saml_link_record_toplevel_skip( $user_id, $hold );

		return $go_to_destination( $hold );
	}

	$issued = agend_apps_saml_link_issue_url( $user_id, $destination, false, AGEND_APPS_SAML_LINK_VIA_SIGNIN );

	if ( '' === $issued['url'] ) {
		return $go_to_destination( $issued['reason'] );
	}

	return array(
		'action' => 'redirect',
		'url'    => $issued['url'],
		'reason' => '',
	);
}

/**
 * `template_redirect` handler, priority 0: the thin hook wrapper around
 * {@see agend_apps_saml_link_start_decision()}.
 *
 * Priority 0 so it runs ahead of everything else that acts on the request,
 * including Agend Content Access' gate at priority 1: a member who has just
 * signed in must reach this step even when the home page it lives on is itself
 * restricted. Only a GET for exactly the site root is a start request, and
 * never from cron, AJAX or REST. The response is `nocache_headers()`'d: it
 * depends on who is asking.
 */
function agend_apps_saml_link_start(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key presence only; the start step carries no secret and does only what the background trigger already does for this member.
	if ( ! isset( $_GET[ AGEND_APPS_SAML_LINK_START_FLAG ] ) ) {
		return;
	}

	if ( wp_doing_cron() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}

	try {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REQUEST_URI is a server-set path/query; only its PATH component is compared, never output.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? rtrim( (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' ) : '';
		$root = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( 'GET' !== $method || $path !== $root ) {
			return;
		}

		$query = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			AGEND_APPS_SAML_LINK_START_FLAG => is_string( $_GET[ AGEND_APPS_SAML_LINK_START_FLAG ] ) ? sanitize_text_field( wp_unslash( $_GET[ AGEND_APPS_SAML_LINK_START_FLAG ] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against this site by wp_validate_redirect() inside the decision; sanitize_text_field() would strip legitimate percent-encoded octets from a URL.
			'redirect_to'                   => isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : '',
		);

		$decision = agend_apps_saml_link_start_decision( get_current_user_id(), $query );

		if ( 'redirect' === $decision['action'] && '' !== $decision['url'] ) {
			nocache_headers();
			wp_safe_redirect( $decision['url'] );
			exit;
		}
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link start step failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'template_redirect', 'agend_apps_saml_link_start', 0 );

// ---------------------------------------------------------------------------
// A. Link before directory content is rendered
// ---------------------------------------------------------------------------

/**
 * What the current request is, for the pre-render trigger: its URL, the page
 * it landed on, and which member content surface it is, if any.
 *
 * `kind` is `listing_detail` for a request carrying the directory listing
 * query var (`agend_dir_listing`, or the legacy `agend_listing`), and
 * `directory_page` for the dedicated directory page
 * (`Agend_Apps_Records_Pages::page_id( 'listing' )`). A listing detail on a
 * page that is not the dedicated directory page, while one is configured, is
 * left alone: the `wp` 4 redirect (`includes/records/pages.php`) sends it to
 * the dedicated page first, and the trigger fires there. `kind` is '' for
 * anything else.
 *
 * @return array{kind: string, url: string, page_id: int, listing_slug: string}
 */
function agend_apps_saml_link_prerender_context(): array {
	$slug = sanitize_title( (string) get_query_var( 'agend_dir_listing' ) );

	if ( '' === $slug ) {
		$slug = sanitize_title( (string) get_query_var( 'agend_listing' ) );
	}

	$page_id      = (int) get_queried_object_id();
	$directory_id = class_exists( 'Agend_Apps_Records_Pages' ) ? Agend_Apps_Records_Pages::page_id( 'listing' ) : 0;
	$kind         = '';

	if ( '' !== $slug ) {
		if ( 0 === $directory_id || $directory_id === $page_id ) {
			$kind = 'listing_detail';
		}
	} elseif ( $directory_id > 0 && $directory_id === $page_id ) {
		$kind = 'directory_page';
	}

	return array(
		'kind'         => $kind,
		'url'          => agend_apps_saml_link_current_url(),
		'page_id'      => $page_id,
		'listing_slug' => $slug,
	);
}

/**
 * The pre-render trigger's pure decision: the URL to redirect the current
 * request to, or '' to carry on and render.
 *
 * Every guard, cheapest first:
 * - a front-end main-document request: not wp-admin, AJAX, cron, REST, a feed
 *   or an embed; a GET; the main query;
 * - a signed-in member;
 * - a browser navigation, not a prefetch, frame or script fetch
 *   ({@see agend_apps_saml_link_navigation_request()}), and not an editor or
 *   preview render ({@see agend_apps_saml_link_preview_request()});
 * - not a request that is itself part of the SAML round trip: the markers
 *   {@see agend_apps_saml_link_request_eligible()} excludes, which include the
 *   done flag and the start flag;
 * - not a blocked surface ({@see agend_apps_saml_link_surface_blocked()});
 * - the toggle is on, and the request is a member content page. That set is
 *   the dedicated directory page and a listing detail
 *   ({@see agend_apps_saml_link_prerender_context()}) by default, and is
 *   filterable through `agend_apps_saml_link_prerender_required`;
 * - the member is eligible, and the loop guard holds nothing back
 *   ({@see agend_apps_saml_link_toplevel_hold()}). A hold is recorded for
 *   diagnostics. This is what keeps a member who has just come back from a
 *   round trip, linked or not, on the page they asked for.
 *
 * Then the URL is minted by {@see agend_apps_saml_link_issue_url()}, non-framed
 * and returning to the current URL, which also counts the attempt and stamps
 * the loop-guard marker.
 *
 * @return string The IdP-initiated URL to redirect to, or ''.
 */
function agend_apps_saml_link_prerender_target(): string {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() ) {
		return '';
	}

	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return '';
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

	if ( 'GET' !== $method || ! is_main_query() || ! is_user_logged_in() ) {
		return '';
	}

	if ( ! agend_apps_saml_link_navigation_request() || agend_apps_saml_link_preview_request() ) {
		return '';
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REQUEST_URI is a server-set path/query; only its PATH component is used, for a marker-string comparison, never output.
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key PRESENCE only (never a value), to detect a request that is itself part of the SAML round trip; never output or stored.
	if ( ! agend_apps_saml_link_request_eligible( $method, $path, $_GET ) || agend_apps_saml_link_surface_blocked() ) {
		return '';
	}

	$user_id = get_current_user_id();

	// Eligibility before anything that reads the request's page: it is the
	// cheap exit for the common case (a member who is already linked), and the
	// existing trigger pays for it on every page view anyway.
	if ( ! Agend_Apps_Settings::prerender_link_enabled() || ! agend_apps_saml_link_eligibility( $user_id )['eligible'] ) {
		return '';
	}

	$context = agend_apps_saml_link_prerender_context();

	/**
	 * Filters whether the current request is a member content page that must
	 * not be built until the member is linked: the pre-render trigger redirects
	 * an unlinked, signed-in member through the SAML round trip first.
	 *
	 * Defaults to true for the dedicated directory page and a directory
	 * listing detail. Every other guard (signed-in, GET, main query, not a
	 * round-trip request, eligibility, the loop guard) still applies to a page
	 * that opts in.
	 *
	 * @param bool  $required Whether this request is a member content page.
	 * @param array $context  {kind: string, url: string, page_id: int, listing_slug: string}.
	 */
	if ( ! apply_filters( 'agend_apps_saml_link_prerender_required', '' !== $context['kind'], $context ) ) {
		return '';
	}

	$hold = agend_apps_saml_link_toplevel_hold( $user_id, AGEND_APPS_SAML_LINK_VIA_PRERENDER );

	if ( '' !== $hold ) {
		agend_apps_saml_link_record_toplevel_skip( $user_id, $hold );

		return '';
	}

	return agend_apps_saml_link_issue_url( $user_id, $context['url'], false, AGEND_APPS_SAML_LINK_VIA_PRERENDER )['url'];
}

/**
 * `wp` handler, priority 3: sends an unlinked member through the SAML round
 * trip before a directory page is built.
 *
 * Priority 3 is load-bearing. The server-rendered listing detail swap and its
 * gateway call run on `wp` at priority 5, and the sign-in gate for gated
 * listing detail on `wp` at priority 4; `wp` is also the earliest the main
 * query is parsed, which the decision needs. So this runs after the query is
 * known and before any directory data is fetched. It does not interfere with
 * the priority 4 gate, which only concerns signed-out visitors: this only
 * concerns signed-in members. A 302, `nocache_headers()`'d: the answer depends
 * on who is asking.
 */
function agend_apps_saml_link_prerender_maybe_redirect(): void {
	try {
		$target = agend_apps_saml_link_prerender_target();

		if ( '' === $target ) {
			return;
		}

		nocache_headers();
		wp_safe_redirect( $target );
		exit;
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link pre-render trigger failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'wp', 'agend_apps_saml_link_prerender_maybe_redirect', 3 );
