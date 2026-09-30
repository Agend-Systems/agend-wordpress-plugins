<?php
/**
 * WordPress-as-IdP identity link via a SAML round trip.
 *
 * An Agend identity may only be created from a signed SAML assertion (the
 * server-to-server link this plugin used to offer, `includes/wp-idp-link.php`'s
 * former `agend_apps_wp_idp_link_user()`, is retired). This file is what makes
 * `wordpress` sign-in mode actually link a member when the resolved mechanism
 * is `saml` (`Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML`): it passes a
 * signed-in member through the site's own SAML identity provider plugin
 * (agend-saml-idp) once, so the plugin's normal IdP-initiated flow posts a
 * real assertion to the gateway's ACS. The gateway then provisions the user,
 * records `sso_identities`, maps the role, creates the CRM contact, and
 * redirects back to this site because the RelayState origin matches the
 * connection's own IdP host -- all of that already works and is untouched by
 * this file.
 *
 * This file used to drive that trip with two visible hops (a `login_redirect`
 * handoff page, then a `template_redirect` redirect into the IdP), because of
 * a WordPress cookie-timing fact: at `login_redirect` time the auth cookies
 * `wp_signon()` just set are not yet present in `$_COOKIE`, so a nonce minted
 * there would be bound to the wrong (pre-login) session. That two-hop dance
 * is gone. The trigger is now a single inert placeholder rendered in
 * `wp_footer`: by the time `wp_footer` runs, the response that sets the auth
 * cookies has already been sent and the NEXT request (the placeholder's own
 * background fetch) carries them, so there is no first hop to wait out.
 *
 * The new shape, in order:
 * 1. `template_redirect` (this file, default priority) decides once per
 *    request whether this member is eligible and, if so, marks a REST
 *    endpoint URL as pending for `wp_footer` to pick up. Running the decision
 *    here rather than at `wp_footer` matters for two reasons: the one
 *    permitted visible fallback redirect (see below) is a real redirect,
 *    which must happen before any output; and deciding once per request,
 *    rather than once per hook, keeps the view/render counters honest.
 * 2. `wp_footer` echoes an inert `<iframe>` placeholder plus a small inline
 *    script, built by {@see agend_apps_saml_link_placeholder_markup()}. The
 *    markup carries no nonce, no member id, nothing session-bound -- it is
 *    safe to serve out of a full-page cache to any visitor. The script reads
 *    the per-user REST nonce `agend-apps-core.php` already exposes as
 *    `window.agendApps.nonce` (SEE THAT FILE's `agend_apps_output_config_js()`)
 *    and fetches the sso-url endpoint with it.
 * 3. `GET /agend-apps/v1/identity-link/sso-url` (see
 *    `includes/rest/identity-link-routes.php`) is the ONLY place the per-user
 *    nonce travels and the ONLY place a nonce'd IdP URL is minted. Because
 *    that call happens after the page has already loaded, it is never part of
 *    the cached HTML: a page cache serving one member's HTML to another only
 *    ever leaks the inert placeholder, never a credential.
 * 4. If the endpoint returns a URL, the script points the hidden iframe's
 *    `src` at it, and the IdP-initiated flow runs inside that iframe.
 *
 * Why the nonce must never appear in the footer markup itself: a page cache
 * cannot know which visitor's nonce belongs in a cached response, so it would
 * either serve visitor A's nonce to visitor B (a `wp_verify_nonce()` bound to
 * the wrong session, which the IdP plugin already fails closed on -- see
 * `WP_SAML_IDP_Auth::handle_idp_initiated_sso()`) or, if the whole markup were
 * excluded from the cache to avoid that, silently never render at all. Both
 * failure shapes are silent: the member sees nothing wrong, and the link
 * simply never happens. Minting the nonce only inside the REST response
 * removes the cache from the trust boundary entirely.
 *
 * Signing in always leads to an attempt. `wp_login` sets a one-time marker
 * for an eligible member ({@see agend_apps_saml_link_schedule_on_login()}),
 * and the first page after it renders the placeholder wherever it lands: on
 * the front end through the flow above, and in wp-admin through
 * `admin_footer` ({@see agend_apps_saml_link_render_admin_placeholder()}),
 * where `template_redirect` never runs. wp-admin renders only while that
 * marker is pending and never takes the visible fallback. When the trigger
 * stands down for an eligible member, the reason is recorded
 * ({@see agend_apps_saml_link_record_skip()}) for the diagnostics panel.
 *
 * That silence is exactly what the one visible-redirect fallback
 * (`AGEND_APPS_SAML_LINK_FALLBACK_VIEWS`, `AGEND_APPS_LINK_MAX_ATTEMPTS`
 * in {@see agend_apps_saml_link_decision()}) exists to rescue: a site whose
 * theme never calls `wp_footer`, or whose cache configuration breaks the
 * background fetch, would otherwise leave every member permanently unlinked
 * with no visible symptom. The fallback trades a single visible redirect,
 * once per member, for the guarantee that a mis-configured site still
 * eventually gets a shot at linking every member.
 *
 * Every hook here is guarded on `Agend_Apps_Settings::sso_link_mechanism()`
 * being `saml`, re-evaluated per request like the rest of the plugin: a site
 * on `disabled`, or one where `auto` currently resolves to `disabled` because
 * no SAML IdP plugin is detected, must never render the placeholder or issue
 * an IdP URL. Every hook wraps its work in `try`/`catch`: a defect here must
 * never break a WordPress page view.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query key on the same-origin "done" URL the IdP-initiated flow's
 * RelayState points back to. Its PRESENCE (with a valid nonce) is what
 * {@see agend_apps_saml_link_done_decision()} recognises as "the round trip
 * came back", regardless of whether it landed in the hidden iframe or, via
 * the visible fallback, the top-level page.
 *
 * @var string
 */
const AGEND_APPS_SAML_LINK_DONE_FLAG = 'agend_apps_saml_link_done';

/**
 * `wp_create_nonce()`/`wp_verify_nonce()` action name for the done URL's
 * `_wpnonce`. A distinct action from the IdP's own `wp_saml_idp_sso_*`
 * nonce: this one is verified by THIS plugin, not agend-saml-idp, and scopes
 * a forged done URL to nothing more than marking `completed` early.
 *
 * @var string
 */
const AGEND_APPS_SAML_LINK_DONE_NONCE = 'agend_apps_saml_link_done';

/**
 * How many eligible front-end page views with ZERO attempts made must pass
 * before the visible fallback redirect fires. Covers the case the attempt
 * counter alone cannot see: a theme that never calls `wp_footer`, so the
 * placeholder is scheduled (`views` climbs) but never actually rendered and
 * therefore never has a chance to attempt anything.
 *
 * @var int
 */
const AGEND_APPS_SAML_LINK_FALLBACK_VIEWS = 3;

/**
 * User meta key for the one-time "signed in, attempt pending" marker set on
 * `wp_login` ({@see agend_apps_saml_link_schedule_on_login()}). Holds the
 * sign-in time. The next page that renders the placeholder, in wp-admin or on
 * the front end, consumes it.
 *
 * @var string
 */
const AGEND_APPS_SAML_LINK_LOGIN_PENDING_META = '_agend_apps_saml_link_login_pending';

/**
 * How long a sign-in marker stays usable. A member who signs in and then
 * leaves should not have wp-admin fire a round trip days later on the strength
 * of that old sign-in; the next sign-in sets a fresh marker.
 *
 * @var int
 */
const AGEND_APPS_SAML_LINK_LOGIN_PENDING_TTL = 24 * HOUR_IN_SECONDS;

/**
 * User meta key for the last reason the trigger stood down for an eligible
 * member ({@see agend_apps_saml_link_record_skip()}), shown in the Identity
 * and SSO diagnostics panel so a "Never attempted" member explains itself.
 *
 * @var string
 */
const AGEND_APPS_SAML_LINK_SKIP_META = '_agend_apps_saml_link_skip';

/**
 * Resolves this site's registered Agend SP entity id from agend-saml-idp's
 * own service-provider registry (`wp_saml_idp_service_providers`, read via
 * {@see WP_SAML_IDP_Service_Provider::get_service_providers()}).
 *
 * An entity id is recognised as the Agend SP by shape: it contains
 * `/api/auth/sso/`. When more than one candidate matches (a site connected to
 * more than one Agend environment or account), the one containing this
 * site's configured account slug wins, then the one whose host matches
 * {@see Agend_Apps_Settings::get_root_url()}, then the first candidate found.
 *
 * Returns '' when the registry holds no matching candidate, rather than
 * fabricating the expected shape (`{root}/api/auth/sso/{slug}/metadata`) the
 * way an earlier version of this function did. Fabricating an entity id
 * nothing ever actually registered turns a configuration gap (the SAML IdP
 * plugin has not yet registered this site's connection) into a raw
 * `wp_die( 'SAML Error: Service Provider not found' )` on a member-facing
 * page the moment the placeholder's fetch resolves. The honest empty answer
 * instead lets {@see agend_apps_saml_link_eligibility()} report
 * `sp_not_registered` and stand down until the registry catches up.
 *
 * @return string The resolved entity id, or '' when nothing resolves.
 */
function agend_apps_saml_agend_sp_entity_id(): string {
	$result = '';

	if ( class_exists( 'WP_SAML_IDP_Service_Provider' ) && method_exists( 'WP_SAML_IDP_Service_Provider', 'get_service_providers' ) ) {
		$providers  = WP_SAML_IDP_Service_Provider::get_service_providers();
		$candidates = array();

		if ( is_array( $providers ) ) {
			foreach ( $providers as $provider ) {
				if ( ! is_array( $provider ) || ! isset( $provider['entityId'] ) || ! is_string( $provider['entityId'] ) ) {
					continue;
				}

				if ( false === strpos( $provider['entityId'], '/api/auth/sso/' ) ) {
					continue;
				}

				$candidates[] = $provider['entityId'];
			}
		}

		if ( 1 === count( $candidates ) ) {
			$result = $candidates[0];
		} elseif ( count( $candidates ) > 1 ) {
			$slug = method_exists( 'Agend_Apps_Settings', 'get_account_slug' ) ? Agend_Apps_Settings::get_account_slug() : '';

			if ( '' !== $slug ) {
				foreach ( $candidates as $candidate ) {
					if ( false !== strpos( $candidate, $slug ) ) {
						$result = $candidate;
						break;
					}
				}
			}

			if ( '' === $result ) {
				$root = method_exists( 'Agend_Apps_Settings', 'get_root_url' ) ? Agend_Apps_Settings::get_root_url() : '';
				$host = ( '' !== $root ) ? wp_parse_url( $root, PHP_URL_HOST ) : '';

				if ( is_string( $host ) && '' !== $host ) {
					foreach ( $candidates as $candidate ) {
						if ( false !== strpos( $candidate, $host ) ) {
							$result = $candidate;
							break;
						}
					}
				}
			}

			if ( '' === $result ) {
				$result = $candidates[0];
			}
		}
	}

	/**
	 * Filters the Agend SP entity id resolved for the SAML link trigger.
	 *
	 * @param string $result Resolved entity id, or '' when none resolved.
	 */
	return (string) apply_filters( 'agend_apps_saml_agend_sp_entity_id', $result );
}

/**
 * Whether a resolved SP entity id is both registered and enabled with
 * agend-saml-idp.
 *
 * Guarded on the IdP plugin's registry class and lookup method existing at
 * all: an inactive/missing agend-saml-idp reports `registered: false` rather
 * than fataling. An empty `$entity_id` short-circuits the same way, since
 * `get_sp_by_entity_id( '' )` is never a meaningful lookup.
 *
 * `enabled` mirrors `WP_SAML_IDP_Service_Provider::is_service_provider_enabled()`'s
 * own backwards-compatible default: an SP array with no `enabled` key at all
 * (a connection registered before that key existed) is treated as enabled,
 * not disabled, matching the IdP plugin's own behaviour rather than silently
 * disagreeing with it.
 *
 * @param string $entity_id Entity id to check.
 * @return array{registered: bool, enabled: bool}
 */
function agend_apps_saml_sp_ready( string $entity_id ): array {
	$not_ready = array(
		'registered' => false,
		'enabled'    => false,
	);

	if ( '' === $entity_id ) {
		return $not_ready;
	}

	if ( ! class_exists( 'WP_SAML_IDP_Service_Provider' ) || ! method_exists( 'WP_SAML_IDP_Service_Provider', 'get_sp_by_entity_id' ) ) {
		return $not_ready;
	}

	$sp = WP_SAML_IDP_Service_Provider::get_sp_by_entity_id( $entity_id );

	if ( ! is_array( $sp ) ) {
		return $not_ready;
	}

	$enabled = isset( $sp['enabled'] ) ? (bool) $sp['enabled'] : true;

	return array(
		'registered' => true,
		'enabled'    => $enabled,
	);
}

/**
 * Whether the CURRENT request may carry the footer placeholder at all,
 * independent of the member's own eligibility.
 *
 * Deliberately excludes anything that is not an ordinary front-end page
 * view -- a non-GET request (a form submit must not sprout a background SSO
 * round trip), `wp-login.php` and the `/saml/` path prefix some SAML plugins
 * route through, and every shape of request that is itself PART of the SAML
 * round trip this file drives or that agend-saml-idp's own dispatcher
 * handles (`saml`, `idp_initiated`, `SAMLRequest`, `saml_action`, `option` --
 * agend-saml-idp reads its admin/dispatch action from one of these depending
 * on entry point). The done-flag key
 * ({@see AGEND_APPS_SAML_LINK_DONE_FLAG}) is excluded for the same reason:
 * the same-origin done URL this file itself builds must never re-arm the
 * trigger that produced it, or a frame landing on it would schedule another
 * placeholder on the very page that is supposed to end the round trip.
 *
 * @param string $method Request method, e.g. `$_SERVER['REQUEST_METHOD']`.
 * @param string $path   Request path only (no query string), e.g. from
 *                       `wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH )`.
 * @param array  $query  The current request's `$_GET` (only key PRESENCE is
 *                       read; values are never used).
 * @return bool
 */
function agend_apps_saml_link_request_eligible( string $method, string $path, array $query ): bool {
	if ( 'GET' !== $method ) {
		return false;
	}

	if ( false !== strpos( $path, 'wp-login.php' ) || 0 === strpos( $path, '/saml/' ) ) {
		return false;
	}

	foreach ( array( 'saml', 'idp_initiated', 'SAMLRequest', 'saml_action', 'option', AGEND_APPS_SAML_LINK_DONE_FLAG ) as $marker ) {
		if ( isset( $query[ $marker ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Whether the current front-end surface must never carry the placeholder,
 * even on a plain GET that passed {@see agend_apps_saml_link_request_eligible()}.
 *
 * A feed is not a page a browser renders (no `wp_footer` script would ever
 * run there, so scheduling the placeholder would be pure waste), and
 * WooCommerce's cart, checkout and account form pages are excluded because a
 * member mid-purchase or mid-account-form must not have a background SSO
 * round trip racing that page's own state (a redirect inside the iframe, a
 * session refresh, or simply the extra request) while it is in flight. Each
 * WooCommerce check is behind its own `function_exists()` guard, since
 * WooCommerce may not be installed at all.
 *
 * The one account page allowed is the read-only My Account dashboard: an
 * account page with no endpoint, which holds no form. It is often the first
 * page a member sees after signing in, so blocking it left members who land
 * there with no link attempt at all. Every account endpoint (edit-account,
 * edit-address, payment-methods, orders and the rest) stays blocked, and so
 * does every account page when `is_wc_endpoint_url()` is unavailable, since
 * the dashboard cannot then be told apart from a form.
 *
 * @return bool
 */
function agend_apps_saml_link_surface_blocked(): bool {
	if ( is_feed() ) {
		return true;
	}

	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return true;
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return true;
	}

	if ( function_exists( 'is_account_page' ) && is_account_page() ) {
		return ! function_exists( 'is_wc_endpoint_url' ) || is_wc_endpoint_url();
	}

	return false;
}

/**
 * Builds the same-origin "done" URL the IdP-initiated flow's RelayState
 * points back to.
 *
 * `_wpnonce` and (when present) `redirect_to` are `rawurlencode()`'d here,
 * before `add_query_arg()`: real WordPress's `add_query_arg()` does not
 * encode its values itself (`_http_build_query( ..., false )`), so this is
 * the single point of encoding on the wire, matching the convention this
 * file and `includes/account-link-state.php` already use.
 *
 * `frame` distinguishes the two ways this URL is ever used: `true` for the
 * hidden-iframe round trip (the done handler responds with a tiny inert
 * document and never redirects the top-level page), `false` for the one
 * visible fallback redirect (the done handler redirects the browser on to
 * `$return_url` once the round trip completes at the top level).
 * `redirect_to` is only meaningful in that second case, so it is omitted
 * entirely when `$frame` is true or `$return_url` is empty.
 *
 * @param string $return_url Where a non-framed round trip should land once done. Ignored when `$frame` is true.
 * @param bool   $frame      Whether this done URL is reached inside the hidden iframe.
 * @return string
 */
function agend_apps_saml_link_done_url( string $return_url, bool $frame ): string {
	$args = array(
		AGEND_APPS_SAML_LINK_DONE_FLAG => '1',
		'_wpnonce'                     => rawurlencode( wp_create_nonce( AGEND_APPS_SAML_LINK_DONE_NONCE ) ),
	);

	if ( $frame ) {
		$args['frame'] = '1';
	} elseif ( '' !== $return_url ) {
		$args['redirect_to'] = rawurlencode( wp_validate_redirect( $return_url, home_url( '/' ) ) );
	}

	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Builds the nonce'd IdP-initiated SSO URL agend-saml-idp's own dispatcher
 * recognises, exactly the shape this file has always built.
 *
 * `sp` and `RelayState` are `rawurlencode()`'d here, before `add_query_arg()`
 * -- see {@see agend_apps_saml_link_done_url()}'s docblock for why. The
 * receiving (IdP) side runs `sanitize_text_field( wp_unslash( $_GET['sp'] ) )`,
 * which yields the exact entity id back.
 *
 * @param string $entity_id Resolved Agend SP entity id.
 * @param string $done_url  The same-origin done URL to use as RelayState.
 * @return string
 */
function agend_apps_saml_link_idp_url( string $entity_id, string $done_url ): string {
	return add_query_arg(
		array(
			'idp_initiated' => '1',
			'sp'            => rawurlencode( $entity_id ),
			'RelayState'    => rawurlencode( $done_url ),
			'_wpnonce'      => wp_create_nonce( 'wp_saml_idp_sso_' . $entity_id ),
		),
		home_url( '/' )
	);
}

/**
 * The shared eligibility gate: whether a given WordPress user should be sent
 * through the SAML link round trip at all, and if not, exactly why.
 *
 * Called by BOTH the `template_redirect` trigger below and the identity-link
 * REST endpoint (`includes/rest/identity-link-routes.php`) -- the endpoint
 * must never trust the browser's own idea of whether it should ask for a URL,
 * since the browser is exactly the thing a stale cache or a replayed request
 * could be confused about.
 *
 * This function is a PURE READ: it records nothing, regardless of outcome.
 * Recording (the `sp_not_registered`/`sp_disabled` error states, the
 * `attempts` counter, the `asserted` state) happens only in the two callers
 * that actually act on this result --
 * {@see agend_apps_saml_link_issue_url()} and
 * {@see agend_apps_saml_link_decision()} -- never here, so a caller that only
 * wants to know "would this succeed" (a diagnostics panel, a future status
 * check) can call this freely with no side effect.
 *
 * Evaluated in order; the first failing reason wins:
 * 1. `signed_out` -- no WordPress user.
 * 2. `site_moved` -- this site's stored connection was stamped under a
 *    different `site_url()` ({@see agend_apps_connect_site_moved()}). Checked
 *    FIRST among the site-level checks, before mode/mechanism/anything else:
 *    a moved (cloned) site must stand down regardless of what it is
 *    otherwise configured to do, and checking it first means no later check
 *    can ever short-circuit past it.
 * 3. `mode_not_wordpress` -- this site is not in `wordpress` sign-in mode.
 * 4. `mechanism_not_saml` -- the resolved link mechanism is not `saml`.
 * 5. `linked` -- already linked; nothing to do.
 * 6. `no_external_id` -- no external id resolves for this member.
 * 7. `sp_not_registered` -- {@see agend_apps_saml_agend_sp_entity_id()} found nothing.
 * 8. `sp_not_registered` / `sp_disabled` -- the resolved entity id is not
 *    registered with agend-saml-idp, or is registered but disabled.
 * 9. `connection_pending_approval` -- the connection this site made
 *    ({@see agend_apps_connect_stored()}) is still awaiting Agend approval.
 * 10. `attempt_cap` -- the lifetime attempt cap has been reached.
 *
 * @param int $user_id WordPress user id (0 = signed out).
 * @return array{eligible: bool, reason: string, entity_id: string} `reason` and
 *         `entity_id` are '' when `eligible` is true / the gate never got far
 *         enough to resolve an entity id, respectively -- except `entity_id`
 *         IS populated on the successful path.
 */
function agend_apps_saml_link_eligibility( int $user_id ): array {
	$fail = static function ( string $reason ): array {
		return array(
			'eligible'  => false,
			'reason'    => $reason,
			'entity_id' => '',
		);
	};

	if ( 0 === $user_id ) {
		return $fail( 'signed_out' );
	}

	// A moved (cloned) site must stand down regardless of its mode, mechanism,
	// or anything else -- see {@see agend_apps_connect_site_moved()}'s
	// docblock for the threat this closes. Guarded on function_exists()
	// because connect-site.php, while always loaded by the bootstrap ahead of
	// this file, is not a hard dependency this file otherwise takes.
	// Deliberately records NO state on this reason: the clone's user meta
	// (including this member's link state) is a COPY of the original site's,
	// so writing an error state into it is either useless (the clone is
	// thrown away) or actively misleading (this database is later restored
	// back over the real site, carrying a bogus error state with it).
	if ( function_exists( 'agend_apps_connect_site_moved' ) && agend_apps_connect_site_moved() ) {
		return $fail( 'site_moved' );
	}

	if ( ! Agend_Apps_Settings::wordpress_idp_enabled() ) {
		return $fail( 'mode_not_wordpress' );
	}

	if ( Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML !== Agend_Apps_Settings::sso_link_mechanism() ) {
		return $fail( 'mechanism_not_saml' );
	}

	$stored = agend_apps_wp_idp_link_state( $user_id );

	if ( AGEND_APPS_LINK_STATE_LINKED === $stored['state'] ) {
		return $fail( 'linked' );
	}

	if ( '' === agend_apps_user_external_id( $user_id ) ) {
		return $fail( 'no_external_id' );
	}

	$entity_id = agend_apps_saml_agend_sp_entity_id();

	if ( '' === $entity_id ) {
		return $fail( 'sp_not_registered' );
	}

	$sp_ready = agend_apps_saml_sp_ready( $entity_id );

	if ( ! $sp_ready['registered'] ) {
		return $fail( 'sp_not_registered' );
	}

	if ( ! $sp_ready['enabled'] ) {
		return $fail( 'sp_disabled' );
	}

	// The connection this site made is still awaiting Agend approval: the
	// gateway's ACS refuses an unapproved connection's assertion outright, so
	// it never reaches RelayState, the done URL never fires, `completed` is
	// never set, and `promote()` never runs. Checked here, after the
	// SP-readiness checks (the SP genuinely is ready; approval is a separate,
	// account-level gate) and before the attempt-cap check below: pending
	// approval is the NORMAL state immediately after connecting, so without
	// this check every member on a freshly connected site would burn all
	// three attempts and then trigger the one visible fallback redirect
	// straight into a round trip that cannot succeed, landing them on a
	// gateway error page. Standing down here and recording nothing is
	// correct: nothing on the WordPress side is broken, and nothing it does
	// can hurry a staff approval, so there is nothing to record and nothing
	// to back off from.
	if ( function_exists( 'agend_apps_connect_stored' ) && 'pending' === agend_apps_connect_stored()['approval_state'] ) {
		return $fail( 'connection_pending_approval' );
	}

	if ( $stored['attempts'] >= AGEND_APPS_LINK_MAX_ATTEMPTS ) {
		// Unlike every other failure above, this one still reports the
		// resolved `entity_id`: the SP is genuinely ready, only the lifetime
		// attempt count is the problem. {@see agend_apps_saml_link_issue_url()}
		// relies on that to mint the one non-framed fallback attempt a capped
		// member is still owed, without re-resolving the SP itself.
		return array(
			'eligible'  => false,
			'reason'    => 'attempt_cap',
			'entity_id' => $entity_id,
		);
	}

	return array(
		'eligible'  => true,
		'reason'    => '',
		'entity_id' => $entity_id,
	);
}

/**
 * Records the `error` state for the two eligibility reasons that mean "the
 * SAML IdP plugin's registry is not ready for this member yet" --
 * `sp_not_registered` and `sp_disabled` -- shared by
 * {@see agend_apps_saml_link_issue_url()} and
 * {@see agend_apps_saml_link_decision()} so the copy backing this decision
 * lives in exactly one place. Recording `error` engages the standard
 * `AGEND_APPS_LINK_BACKOFF_ERROR` window, which is what stops a broken
 * registry from being re-checked on every single page view.
 *
 * No-op for any other reason.
 *
 * @param int    $user_id WordPress user id.
 * @param string $reason  A reason from {@see agend_apps_saml_link_eligibility()}.
 */
function agend_apps_saml_link_record_registry_error( int $user_id, string $reason ): void {
	if ( 'sp_not_registered' !== $reason && 'sp_disabled' !== $reason ) {
		return;
	}

	agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_ERROR, $reason );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[Agend Apps] SAML link registry not ready for user ' . $user_id . ': ' . $reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}

/**
 * The ONLY place a nonce'd IdP-initiated URL is minted and the ONLY place an
 * SSO attempt is counted.
 *
 * Called both by the identity-link REST endpoint (the framed, background
 * path) and by the visible-redirect fallback in
 * {@see agend_apps_saml_link_decision()} (the non-framed path) -- the two
 * differ only in `$frame` and in whether `$return_url` is meaningful (the
 * REST endpoint has no return url to give; the browser is already where it
 * needs to be once the iframe finishes).
 *
 * Not eligible: returns an empty url with the failing reason. For exactly
 * `sp_not_registered` and `sp_disabled`, also records the `error` state via
 * {@see agend_apps_saml_link_record_registry_error()} so the backoff window
 * stops the site re-checking a broken registry on every page view. No state
 * write for any other ineligible reason.
 *
 * ONE exception to "not eligible means no URL": `attempt_cap` combined with
 * `$frame === false`. The lifetime cap exists to stop the AUTOMATIC
 * background attempts (`$frame === true`, from the REST endpoint) from
 * retrying forever; it does not exist to deny the one visible fallback
 * redirect a capped member is specifically owed (see
 * `agend_apps_saml_link_decision()`'s file docblock: "a capped member is
 * exactly who the frame-blocked fallback exists for"). Since the only caller
 * that ever passes `$frame === false` is that fallback, and it only ever
 * does so once (`fallback_done` blocks a second call), this exception can
 * never be reached by the background path and never grants more than the one
 * extra attempt the fallback is allowed. `agend_apps_saml_link_eligibility()`
 * still reports the resolved `entity_id` on an `attempt_cap` failure for
 * exactly this reason.
 *
 * Also refuses while the member is inside a backoff window (`throttled`).
 * {@see agend_apps_saml_link_decision()} has already checked that before it
 * reaches its fallback branch, so this is never the throttle that matters for
 * the trigger itself; it matters because the REST endpoint is reachable
 * DIRECTLY by anything running on the page (the `wp_rest` nonce is exposed as
 * `window.agendApps.nonce` for every logged-in visitor). Without this check a
 * member -- or a script on a page they are viewing -- could call the endpoint
 * three times in a row and burn the entire lifetime cap in one second,
 * collapsing the paced "three silent attempts, then fall back" design into an
 * immediate visible redirect. The cap still bound without this; only the
 * spacing the cap assumes did not.
 *
 * Otherwise eligible (or eligible-via-the-exception-above): builds the done
 * URL and the IdP url, bumps `attempts`, records `asserted`, and clears the
 * token worker's negative cache (so a fresh attempt is not held back by an
 * earlier failed mint) -- then returns the URL.
 *
 * @param int    $user_id    WordPress user id.
 * @param string $return_url Where a non-framed round trip should land once done. Ignored when `$frame` is true.
 * @param bool   $frame      Whether this URL is meant to run inside a hidden iframe.
 * @return array{url: string, reason: string}
 */
function agend_apps_saml_link_issue_url( int $user_id, string $return_url, bool $frame ): array {
	$eligibility = agend_apps_saml_link_eligibility( $user_id );

	$capped_fallback = ( ! $frame && ! $eligibility['eligible'] && 'attempt_cap' === $eligibility['reason'] && '' !== $eligibility['entity_id'] );

	if ( ! $eligibility['eligible'] && ! $capped_fallback ) {
		agend_apps_saml_link_record_registry_error( $user_id, $eligibility['reason'] );

		return array(
			'url'    => '',
			'reason' => $eligibility['reason'],
		);
	}

	if ( agend_apps_wp_idp_link_is_throttled( agend_apps_wp_idp_link_state( $user_id ) ) ) {
		return array(
			'url'    => '',
			'reason' => 'throttled',
		);
	}

	$done_url = agend_apps_saml_link_done_url( $return_url, $frame );
	$idp_url  = agend_apps_saml_link_idp_url( $eligibility['entity_id'], $done_url );

	agend_apps_wp_idp_merge_link_state( $user_id, array( 'attempts' => agend_apps_wp_idp_link_state( $user_id )['attempts'] + 1 ) );
	agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_ASSERTED );

	if ( class_exists( 'Agend_Apps_Token_Worker' ) ) {
		Agend_Apps_Token_Worker::clear_negative_cache( $user_id );
	}

	return array(
		'url'    => $idp_url,
		'reason' => '',
	);
}

/**
 * The `template_redirect` trigger's pure decision, run once per request for
 * a signed-in member on an eligible surface.
 *
 * Order of evaluation:
 * 1. {@see agend_apps_saml_link_eligibility()}. Ineligible for
 *    `sp_not_registered`/`sp_disabled` records the `error` state (via the
 *    same shared helper {@see agend_apps_saml_link_issue_url()} uses) and
 *    returns `skip`. The one exception is `attempt_cap`: rather than skip
 *    outright, evaluation falls through to the fallback test below, because a
 *    capped member -- one who has already had every attempt this trigger will
 *    ever spend on them -- is exactly who the frame-blocked fallback exists
 *    to rescue.
 * 2. Throttle check ({@see agend_apps_wp_idp_link_is_throttled()}): `skip` /
 *    `throttled` while inside an `asserted`/`error` backoff window.
 * 3. The fallback test. The one visible redirect fires when NEITHER
 *    `fallback_done` nor `completed` is already true, AND either: `views`
 *    has reached the threshold with zero attempts made (the theme never
 *    rendered the placeholder at all -- see `includes/wp-idp-link.php`'s
 *    docblock on the `views`/`renders`/`attempts` diagnostic triangle), OR
 *    the attempt cap has been reached (attempts were made but the round trip
 *    never completed, so something -- most likely a cache serving a stale
 *    nonce, or the iframe itself being blocked -- is preventing it from ever
 *    coming back). On firing: marks `fallback_done`, mints the URL via
 *    {@see agend_apps_saml_link_issue_url()} (non-framed, this page as the
 *    return target), and returns `redirect`. An empty URL from that call
 *    (the eligibility re-check inside it failed for some other reason)
 *    returns `skip` with ITS reason instead.
 * 4. If step 1's reason was `attempt_cap` and the fallback did not fire:
 *    `skip` / `attempt_cap`.
 * 5. Otherwise: bump `views` and return `render`.
 *
 * The throttle check runs BEFORE the fallback test deliberately: a capped
 * member is sitting in `asserted`'s own backoff window (recorded the moment
 * their last attempt was issued), so the fallback is only ever offered on the
 * first eligible view AFTER that window elapses, never immediately on the
 * request that hit the cap.
 *
 * @param int    $user_id     WordPress user id (0 = signed out; never eligible).
 * @param string $current_url The current request's URL, used as the fallback
 *                             redirect's return target. Raw/unvalidated.
 * @return array{action: string, url: string, reason: string} `action` is one
 *         of `render`, `redirect`, `skip`.
 */
function agend_apps_saml_link_decision( int $user_id, string $current_url ): array {
	$eligibility = agend_apps_saml_link_eligibility( $user_id );

	$allow_fallback_only = false;

	if ( ! $eligibility['eligible'] ) {
		if ( 'attempt_cap' !== $eligibility['reason'] ) {
			agend_apps_saml_link_record_registry_error( $user_id, $eligibility['reason'] );

			return array(
				'action' => 'skip',
				'url'    => '',
				'reason' => $eligibility['reason'],
			);
		}

		$allow_fallback_only = true;
	}

	$stored = agend_apps_wp_idp_link_state( $user_id );

	if ( agend_apps_wp_idp_link_is_throttled( $stored ) ) {
		return array(
			'action' => 'skip',
			'url'    => '',
			'reason' => 'throttled',
		);
	}

	$fallback_due =
		! $stored['fallback_done'] &&
		! $stored['completed'] &&
		(
			( $stored['views'] >= AGEND_APPS_SAML_LINK_FALLBACK_VIEWS && 0 === $stored['attempts'] ) ||
			$stored['attempts'] >= AGEND_APPS_LINK_MAX_ATTEMPTS
		);

	if ( $fallback_due ) {
		agend_apps_wp_idp_merge_link_state( $user_id, array( 'fallback_done' => true ) );

		$issued = agend_apps_saml_link_issue_url( $user_id, $current_url, false );

		if ( '' === $issued['url'] ) {
			return array(
				'action' => 'skip',
				'url'    => '',
				'reason' => $issued['reason'],
			);
		}

		return array(
			'action' => 'redirect',
			'url'    => $issued['url'],
			'reason' => '',
		);
	}

	if ( $allow_fallback_only ) {
		return array(
			'action' => 'skip',
			'url'    => '',
			'reason' => 'attempt_cap',
		);
	}

	agend_apps_wp_idp_merge_link_state( $user_id, array( 'views' => $stored['views'] + 1 ) );

	return array(
		'action' => 'render',
		'url'    => '',
		'reason' => '',
	);
}

/**
 * Builds the inert footer placeholder: a hidden iframe plus a small inline
 * script that fetches the sso-url endpoint and, when it returns one, points
 * the iframe at it.
 *
 * Carries no nonce, no member id, nothing session-bound -- see the file
 * docblock for why that is the whole point. Every failure path inside the
 * script (missing element, missing global, non-2xx, no `url` in the body) is
 * a silent `return`: this placeholder must never surface an error to a
 * visitor who has no reason to know it exists.
 *
 * @param string $endpoint_url The identity-link sso-url REST endpoint, already `rest_url()`-built.
 * @return string The markup, or '' when `$endpoint_url` is empty.
 */
function agend_apps_saml_link_placeholder_markup( string $endpoint_url ): string {
	if ( '' === $endpoint_url ) {
		return '';
	}

	$endpoint = esc_url( $endpoint_url );

	return '<iframe id="agend-apps-saml-link-frame" aria-hidden="true" tabindex="-1" title="" '
		. 'style="display:none;width:0;height:0;border:0" data-endpoint="' . $endpoint . '"></iframe>'
		. '<script>(function(){'
		. 'var f=document.getElementById("agend-apps-saml-link-frame");'
		. 'if(!f)return;'
		. 'var e=f.getAttribute("data-endpoint");'
		. 'var n=window.agendApps&&window.agendApps.nonce;'
		. 'if(!e||!n)return;'
		. 'fetch(e,{credentials:"same-origin",headers:{"X-WP-Nonce":n}}).then(function(r){return r.json();}).then(function(d){'
		. 'if(d&&d.url){f.src=d.url;}'
		. '}).catch(function(){});'
		. '})();</script>';
}

/**
 * Request-scoped hand-off between the `template_redirect` decision and the
 * `wp_footer` render, over one static rather than a global: nothing outside
 * this file needs it, and it must never survive past the current request.
 *
 * `template_redirect` runs the decision and, when it is `render`, stores the
 * REST endpoint URL here; `wp_footer` reads it back. The decision runs at
 * `template_redirect` rather than `wp_footer` for two reasons: the fallback
 * outcome is a real redirect, which must happen before any output has been
 * sent, and running it exactly once per request (rather than once per hook
 * that happens to fire) is what keeps the `views`/`renders` counters
 * trustworthy as two independent diagnostics.
 *
 * @param string|null $set When given, stores this value and returns it. When
 *                         null (the default), returns the current value.
 * @return string
 */
function agend_apps_saml_link_pending( ?string $set = null ): string {
	static $endpoint = '';

	if ( null !== $set ) {
		$endpoint = $set;
	}

	return $endpoint;
}

/**
 * `wp_login` handler: sets the one-time sign-in marker for an eligible
 * member, so the first page after signing in makes a link attempt wherever it
 * lands. Without it a member who lands in wp-admin never gets one, because the
 * front-end trigger does not run there.
 *
 * Reads eligibility only ({@see agend_apps_saml_link_eligibility()} is a pure
 * read); the attempt cap and the throttle still apply when the attempt is
 * actually issued. Also clears the last recorded skip reason, so the
 * diagnostics panel explains what happened after this sign-in rather than
 * before it.
 *
 * @param string $user_login The signed-in user's login name.
 * @param mixed  $user       The signed-in `WP_User`, when WordPress passes one.
 */
function agend_apps_saml_link_schedule_on_login( string $user_login, $user = null ): void {
	try {
		$user_id = ( is_object( $user ) && isset( $user->ID ) ) ? (int) $user->ID : 0;

		if ( 0 === $user_id ) {
			return;
		}

		if ( ! agend_apps_saml_link_eligibility( $user_id )['eligible'] ) {
			return;
		}

		update_user_meta( $user_id, AGEND_APPS_SAML_LINK_LOGIN_PENDING_META, time() );
		delete_user_meta( $user_id, AGEND_APPS_SAML_LINK_SKIP_META );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link sign-in scheduling failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'wp_login', 'agend_apps_saml_link_schedule_on_login', 10, 2 );

/**
 * When the member's sign-in marker was set, or 0 when there is none or it is
 * older than {@see AGEND_APPS_SAML_LINK_LOGIN_PENDING_TTL}.
 *
 * @param int $user_id WordPress user id.
 * @return int Unix time of the sign-in, or 0.
 */
function agend_apps_saml_link_login_pending( int $user_id ): int {
	if ( 0 === $user_id ) {
		return 0;
	}

	$signed_in_at = (int) get_user_meta( $user_id, AGEND_APPS_SAML_LINK_LOGIN_PENDING_META, true );

	if ( $signed_in_at <= 0 || ( time() - $signed_in_at ) > AGEND_APPS_SAML_LINK_LOGIN_PENDING_TTL ) {
		return 0;
	}

	return $signed_in_at;
}

/**
 * Records why the trigger stood down for this member on this request. Writes
 * only when the reason differs from the one on record, so a member who stays
 * on a blocked surface costs one write, not one per page view.
 *
 * Reasons: `admin_page` (a wp-admin page with no pending sign-in),
 * `blocked_surface` ({@see agend_apps_saml_link_surface_blocked()}),
 * `throttled` (inside a backoff window after an earlier attempt) and
 * `no_nonce` (the page never printed `window.agendApps.nonce`, so the
 * placeholder script could not call the endpoint). `cached_page` is never
 * recorded here: a cached page runs no PHP at all, so it is derived by
 * {@see agend_apps_saml_link_trigger_status()} instead.
 *
 * @param int    $user_id WordPress user id.
 * @param string $reason  The skip reason.
 */
function agend_apps_saml_link_record_skip( int $user_id, string $reason ): void {
	if ( 0 === $user_id ) {
		return;
	}

	$stored = get_user_meta( $user_id, AGEND_APPS_SAML_LINK_SKIP_META, true );

	if ( is_array( $stored ) && isset( $stored['reason'] ) && $reason === $stored['reason'] ) {
		return;
	}

	update_user_meta(
		$user_id,
		AGEND_APPS_SAML_LINK_SKIP_META,
		array(
			'reason'    => $reason,
			'timestamp' => time(),
		)
	);
}

/**
 * Records a skip only for a member who is otherwise eligible. For anyone else
 * the eligibility reason the diagnostics panel already shows is the real
 * explanation, and a skip reason beside it would only mislead.
 *
 * @param int    $user_id WordPress user id.
 * @param string $reason  The skip reason.
 */
function agend_apps_saml_link_record_skip_if_eligible( int $user_id, string $reason ): void {
	if ( agend_apps_saml_link_eligibility( $user_id )['eligible'] ) {
		agend_apps_saml_link_record_skip( $user_id, $reason );
	}
}

/**
 * Whether this request printed `window.agendApps.nonce`, which the
 * placeholder script needs to call the sso-url endpoint. `agend-apps-core.php`
 * prints it on `wp_head` and `admin_head`, so a theme that never calls
 * `wp_head()` leaves the script without it and the attempt fails silently.
 *
 * @param bool $admin Whether this is a wp-admin page.
 * @return bool
 */
function agend_apps_saml_link_nonce_printed( bool $admin ): bool {
	return did_action( $admin ? 'admin_head' : 'wp_head' ) > 0;
}

/**
 * The trigger facts the diagnostics panel shows: the last recorded skip
 * reason, and whether a sign-in is still waiting for its attempt. Pure read.
 *
 * When a sign-in is pending and nothing was recorded after it, the reason is
 * reported as `cached_page`: the member signed in, but no page they loaded
 * since has run this plugin's PHP, which is what a full-page cache does. That
 * is only claimed once the sign-in is more than a minute old, so a member who
 * is mid-way through their first page load is not misreported, and only for a
 * member who is still eligible: for anyone else the eligibility reason is the
 * explanation.
 *
 * @param int $user_id WordPress user id.
 * @return array{reason: string, timestamp: int, login_pending_at: int}
 */
function agend_apps_saml_link_trigger_status( int $user_id ): array {
	$stored = ( 0 !== $user_id ) ? get_user_meta( $user_id, AGEND_APPS_SAML_LINK_SKIP_META, true ) : '';

	$reason    = ( is_array( $stored ) && isset( $stored['reason'] ) ) ? (string) $stored['reason'] : '';
	$timestamp = ( is_array( $stored ) && isset( $stored['timestamp'] ) ) ? (int) $stored['timestamp'] : 0;

	$login_pending_at = agend_apps_saml_link_login_pending( $user_id );

	if (
		$login_pending_at > 0 &&
		$timestamp < $login_pending_at &&
		( time() - $login_pending_at ) > MINUTE_IN_SECONDS &&
		agend_apps_saml_link_eligibility( $user_id )['eligible']
	) {
		$reason    = 'cached_page';
		$timestamp = $login_pending_at;
	}

	return array(
		'reason'           => $reason,
		'timestamp'        => $timestamp,
		'login_pending_at' => $login_pending_at,
	);
}

/**
 * The wp-admin trigger's pure decision. wp-admin only makes an attempt on the
 * first page after signing in (a pending sign-in marker), never on every admin
 * page view, and never uses the visible-redirect fallback: a redirect out of
 * an admin screen would interrupt the member's work.
 *
 * Order of evaluation:
 * 1. No pending sign-in: `skip` / `admin_page`.
 * 2. {@see agend_apps_saml_link_eligibility()}: ineligible skips with its
 *    reason, recording the registry error state the same way the front-end
 *    decision does.
 * 3. Throttle: `skip` / `throttled` inside a backoff window.
 * 4. Otherwise: `render`.
 *
 * It never touches `views` or `renders`. Those are front-end diagnostics, and
 * `views` is what {@see agend_apps_saml_link_decision()} reads for the visible
 * fallback redirect: counting wp-admin pages there would let admin activity
 * trip a front-end redirect with nothing wrong on the front end. A wp-admin
 * attempt is still counted in `attempts` when the endpoint issues its URL.
 *
 * @param int $user_id WordPress user id.
 * @return array{action: string, reason: string} `action` is `render` or `skip`.
 */
function agend_apps_saml_link_admin_decision( int $user_id ): array {
	if ( 0 === agend_apps_saml_link_login_pending( $user_id ) ) {
		return array(
			'action' => 'skip',
			'reason' => 'admin_page',
		);
	}

	$eligibility = agend_apps_saml_link_eligibility( $user_id );

	if ( ! $eligibility['eligible'] ) {
		agend_apps_saml_link_record_registry_error( $user_id, $eligibility['reason'] );

		return array(
			'action' => 'skip',
			'reason' => $eligibility['reason'],
		);
	}

	if ( agend_apps_wp_idp_link_is_throttled( agend_apps_wp_idp_link_state( $user_id ) ) ) {
		return array(
			'action' => 'skip',
			'reason' => 'throttled',
		);
	}

	return array(
		'action' => 'render',
		'reason' => '',
	);
}

/**
 * Promotes a member's link state on the SAML round trip's own return leg, by
 * asking the gateway directly rather than waiting for the next token mint.
 *
 * Called from {@see agend_apps_saml_link_done_decision()} the moment the
 * round trip demonstrably came back (a verified done URL), which is exactly
 * the moment a status check is most likely to have something new to report.
 * Before this, the only way `asserted` ever became `linked` was
 * `Agend_Apps_Token_Worker::provide_token()` promoting it as a side effect of
 * a successful mint (see `includes/class-agend-apps-token-worker.php`) --
 * which meant a member on a key that could read identities but not mint
 * tokens (or one who simply had not yet triggered a mint) could sit in
 * `asserted` indefinitely even though the gateway already considered them
 * linked.
 *
 * Scope gate: checks `sso.identities.read` directly via
 * `Agend_Apps_Key_Scopes::has()`, rather than reusing the token worker's
 * `sso_account_link` feature gate (`sso.identities.read` AND
 * `sso.tokens.create` together, see `includes/records/features.php`). Gating
 * promotion on the mint scope is precisely the coupling this change removes:
 * a key that can read identities must be able to confirm a link even if it
 * can never mint a token. Checking the single scope directly, rather than
 * `agend_apps_records_feature_available( 'sso_account_link' )`, is what makes
 * that true.
 *
 * @param int $user_id WordPress user id (0 = signed out).
 * @return array{state: string, code: string} `state` is the state this call
 *         recorded, or '' when it recorded nothing; `code` is a machine
 *         reason for diagnostics.
 */
function agend_apps_saml_link_promote( int $user_id ): array {
	if ( 0 === $user_id ) {
		return array(
			'state' => '',
			'code'  => 'signed_out',
		);
	}

	if ( ! class_exists( 'Agend_Apps_Key_Scopes' ) || ! Agend_Apps_Key_Scopes::has( 'sso.identities.read' ) ) {
		return array(
			'state' => '',
			'code'  => 'scope_missing',
		);
	}

	$external_id = agend_apps_user_external_id( $user_id );

	if ( '' === $external_id ) {
		return array(
			'state' => '',
			'code'  => 'no_external_id',
		);
	}

	$response = agend_apps_sso_get_link_status( agend_apps_idp_entity_id(), $external_id );

	if ( is_wp_error( $response ) ) {
		if ( 'UNKNOWN_ISSUER' === agend_apps_auth_error_code( $response ) ) {
			agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_PENDING_APPROVAL, 'unknown_issuer' );

			return array(
				'state' => AGEND_APPS_LINK_STATE_PENDING_APPROVAL,
				'code'  => 'unknown_issuer',
			);
		}

		$code = agend_apps_auth_error_code( $response );
		$code = ( '' !== $code ) ? strtolower( $code ) : 'status_failed';

		agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_ERROR, $code );

		return array(
			'state' => AGEND_APPS_LINK_STATE_ERROR,
			'code'  => $code,
		);
	}

	// The house unwrapping for this envelope (account-link-routes.php:139):
	// the gateway returns { success, data: { linked, ... } }, but a filter
	// may have already stripped the envelope, so fall back to the top level.
	$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

	$linked = isset( $data['linked'] ) && (bool) $data['linked'];

	if ( ! $linked ) {
		// Record NOTHING: the member stays in `asserted`, so the existing
		// backoff and the attempt cap keep governing. Recording anything here
		// would either reset the timestamp (restarting the backoff on every
		// return leg) or invent a failure the gateway never reported -- the
		// assertion may simply still be in flight.
		return array(
			'state' => '',
			'code'  => 'not_linked',
		);
	}

	if ( function_exists( 'agend_apps_record_linked_identity' ) ) {
		agend_apps_record_linked_identity( $user_id, $data );
	}

	if ( class_exists( 'Agend_Apps_Token_Worker' ) ) {
		Agend_Apps_Token_Worker::clear_negative_cache( $user_id );
	}

	agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_LINKED );

	return array(
		'state' => AGEND_APPS_LINK_STATE_LINKED,
		'code'  => 'linked',
	);
}

/**
 * The `template_redirect` done-URL handler's pure decision: whether the
 * current request is a legitimate return from the SAML round trip, and what
 * to do about it.
 *
 * `skip` (never records anything) for: no done flag present, a signed-out
 * visitor, or a nonce that fails `wp_verify_nonce()`. A `skip` here must
 * never `wp_die()` or otherwise reveal why -- the request simply renders as
 * whatever ordinary page it would have been, so a forged or replayed done
 * URL learns nothing.
 *
 * Otherwise, `completed => true` is merged into the member's state (the
 * round trip demonstrably was not frame-blocked, regardless of what the
 * gateway itself decided), {@see agend_apps_saml_link_promote()} is called to
 * check the gateway directly rather than waiting for the next token mint, and:
 * - `frame` when `$query['frame'] === '1'`: the wrapper responds with the
 *   tiny inert document from {@see agend_apps_saml_link_done_markup()} and
 *   nothing else.
 * - `redirect` otherwise: the wrapper sends the browser on to
 *   `wp_validate_redirect( $query['redirect_to'], home_url('/') )`.
 *
 * @param int   $user_id Current WordPress user id (0 = signed out).
 * @param array $query   The relevant `$_GET` values: `AGEND_APPS_SAML_LINK_DONE_FLAG`,
 *                       `_wpnonce`, `frame`, `redirect_to`. Raw/unvalidated.
 * @return array{action: string, url: string, state: string, code: string} `action` is
 *         `frame`, `redirect`, or `skip`; `state`/`code` are the promotion outcome
 *         (both '' for every `skip` return).
 */
function agend_apps_saml_link_done_decision( int $user_id, array $query ): array {
	$skip = array(
		'action' => 'skip',
		'url'    => '',
		'state'  => '',
		'code'   => '',
	);

	if ( ! isset( $query[ AGEND_APPS_SAML_LINK_DONE_FLAG ] ) || '1' !== (string) $query[ AGEND_APPS_SAML_LINK_DONE_FLAG ] ) {
		return $skip;
	}

	if ( 0 === $user_id ) {
		return $skip;
	}

	$nonce = isset( $query['_wpnonce'] ) ? (string) $query['_wpnonce'] : '';

	if ( false === wp_verify_nonce( $nonce, AGEND_APPS_SAML_LINK_DONE_NONCE ) ) {
		return $skip;
	}

	agend_apps_wp_idp_merge_link_state( $user_id, array( 'completed' => true ) );

	$promoted = agend_apps_saml_link_promote( $user_id );

	if ( isset( $query['frame'] ) && '1' === (string) $query['frame'] ) {
		return array(
			'action' => 'frame',
			'url'    => '',
			'state'  => $promoted['state'],
			'code'   => $promoted['code'],
		);
	}

	$redirect_to = isset( $query['redirect_to'] ) ? (string) $query['redirect_to'] : '';

	return array(
		'action' => 'redirect',
		'url'    => wp_validate_redirect( $redirect_to, home_url( '/' ) ),
		'state'  => $promoted['state'],
		'code'   => $promoted['code'],
	);
}

/**
 * The tiny inert document served to the hidden iframe once the round trip
 * completes. `noindex` because this URL is never meant to be a destination;
 * an empty body and no script because nothing further needs to happen inside
 * the frame -- the placeholder script that pointed the iframe here already
 * did everything this leg of the trip needed to do.
 *
 * @return string
 */
function agend_apps_saml_link_done_markup(): string {
	return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"></head><body></body></html>';
}

/**
 * `template_redirect` handler, priority 5 (before
 * {@see agend_apps_saml_link_maybe_trigger()}'s default priority): the thin
 * hook wrapper around {@see agend_apps_saml_link_done_decision()}.
 */
function agend_apps_saml_link_done(): void {
	try {
		$query = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce itself is verified below, inside the pure decision; this is only reading it off the request.
		if ( isset( $_GET[ AGEND_APPS_SAML_LINK_DONE_FLAG ] ) ) {
			$query[ AGEND_APPS_SAML_LINK_DONE_FLAG ] = sanitize_text_field( wp_unslash( $_GET[ AGEND_APPS_SAML_LINK_DONE_FLAG ] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read here for the pure decision to verify; nothing is trusted before that verification runs.
			$query['_wpnonce'] = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag, not a security boundary on its own.
			$query['frame'] = isset( $_GET['frame'] ) ? sanitize_text_field( wp_unslash( $_GET['frame'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- validated via wp_validate_redirect() inside the decision before use.
			$query['redirect_to'] = isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '';
		}

		$decision = agend_apps_saml_link_done_decision( get_current_user_id(), $query );

		if ( 'frame' === $decision['action'] ) {
			nocache_headers();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- agend_apps_saml_link_done_markup() builds a fixed, static document with no dynamic input.
			echo agend_apps_saml_link_done_markup();
			exit;
		}

		if ( 'redirect' === $decision['action'] ) {
			wp_safe_redirect( $decision['url'] );
			exit;
		}
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link done handler failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'template_redirect', 'agend_apps_saml_link_done', 5 );

/**
 * `template_redirect` handler, default priority: the thin hook wrapper
 * around {@see agend_apps_saml_link_decision()}.
 *
 * Guards run cheapest-first: `is_admin()` (wp-admin has its own trigger,
 * {@see agend_apps_saml_link_render_admin_placeholder()}), cron, AJAX and REST
 * requests never carry a browser session through to `wp_footer` anyway, then
 * a signed-out visitor, then the request-shape gate
 * ({@see agend_apps_saml_link_request_eligible()}, reusing the exact
 * `phpcs:ignore` comments the original implicit trigger carried -- still
 * accurate, since the values are still read the same way), then the surface
 * gate ({@see agend_apps_saml_link_surface_blocked()}). A blocked surface is
 * recorded as the `blocked_surface` skip reason for an eligible member, and a
 * `throttled` decision as `throttled`.
 *
 * On `redirect`: `wp_safe_redirect()` and `exit`. On `render`: stores the
 * identity-link sso-url REST endpoint via
 * {@see agend_apps_saml_link_pending()} for `wp_footer` to read.
 */
function agend_apps_saml_link_maybe_trigger(): void {
	if ( is_admin() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	try {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REQUEST_URI is a server-set path/query, not user POST data; only its PATH component is used below, and only for a marker-string comparison, never output.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key PRESENCE only (never a value), to detect a request that is itself part of the SAML round trip; never output or stored.
		if ( ! agend_apps_saml_link_request_eligible( $method, $path, $_GET ) ) {
			return;
		}

		if ( agend_apps_saml_link_surface_blocked() ) {
			agend_apps_saml_link_record_skip_if_eligible( get_current_user_id(), 'blocked_surface' );
			return;
		}

		$current_url = home_url( add_query_arg( array() ) );

		$decision = agend_apps_saml_link_decision( get_current_user_id(), $current_url );

		if ( 'skip' === $decision['action'] && 'throttled' === $decision['reason'] ) {
			agend_apps_saml_link_record_skip( get_current_user_id(), 'throttled' );
		}

		if ( 'redirect' === $decision['action'] && '' !== $decision['url'] ) {
			wp_safe_redirect( $decision['url'] );
			exit;
		}

		if ( 'render' === $decision['action'] ) {
			agend_apps_saml_link_pending( rest_url( 'agend-apps/v1/identity-link/sso-url' ) );
		}
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link trigger decision failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'template_redirect', 'agend_apps_saml_link_maybe_trigger' );

/**
 * Emits the placeholder for a scheduled attempt, shared by the front-end and
 * wp-admin footers. Refuses, recording `no_nonce`, when the page never printed
 * `window.agendApps.nonce` ({@see agend_apps_saml_link_nonce_printed()}): the
 * script would only fail silently, and the sign-in marker is kept so a later
 * page that does print it can still make the attempt.
 *
 * Otherwise bumps `renders` (front end only, see
 * {@see agend_apps_saml_link_admin_decision()}), consumes the sign-in marker,
 * clears the last skip reason and returns the markup.
 *
 * @param int    $user_id  WordPress user id.
 * @param string $endpoint The sso-url REST endpoint.
 * @param bool   $admin    Whether this is a wp-admin page.
 * @return string The markup, or '' when nothing is rendered.
 */
function agend_apps_saml_link_emit_placeholder( int $user_id, string $endpoint, bool $admin ): string {
	if ( '' === $endpoint ) {
		return '';
	}

	if ( ! agend_apps_saml_link_nonce_printed( $admin ) ) {
		agend_apps_saml_link_record_skip( $user_id, 'no_nonce' );
		return '';
	}

	if ( ! $admin ) {
		agend_apps_wp_idp_merge_link_state(
			$user_id,
			array( 'renders' => agend_apps_wp_idp_link_state( $user_id )['renders'] + 1 )
		);
	}

	delete_user_meta( $user_id, AGEND_APPS_SAML_LINK_LOGIN_PENDING_META );
	delete_user_meta( $user_id, AGEND_APPS_SAML_LINK_SKIP_META );

	return agend_apps_saml_link_placeholder_markup( $endpoint );
}

/**
 * `wp_footer` handler: echoes the placeholder built by
 * {@see agend_apps_saml_link_placeholder_markup()} when
 * {@see agend_apps_saml_link_maybe_trigger()} scheduled one for this
 * request, via {@see agend_apps_saml_link_emit_placeholder()}.
 */
function agend_apps_saml_link_render_placeholder(): void {
	try {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- agend_apps_saml_link_placeholder_markup() escapes the endpoint URL internally (esc_url()) and everything else is fixed, static markup.
		echo agend_apps_saml_link_emit_placeholder( get_current_user_id(), agend_apps_saml_link_pending(), false );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link placeholder render failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'wp_footer', 'agend_apps_saml_link_render_placeholder' );

/**
 * `admin_footer` handler: the wp-admin trigger. A member who signs in through
 * `wp-login.php` usually lands in wp-admin, where `template_redirect` never
 * runs, so without this they get no link attempt at all.
 *
 * Keeps the front-end trigger's request guards (no cron, AJAX or REST request,
 * a signed-in member, and {@see agend_apps_saml_link_request_eligible()}'s
 * request-shape markers). It also skips iframe and modal admin screens (the
 * plugin information modal, the media upload frame, the Customizer), which
 * define `IFRAME_REQUEST` or carry `iframe` in the query: the sign-in marker
 * is kept for the next ordinary admin page. Then it defers to
 * {@see agend_apps_saml_link_admin_decision()}. The decision can run at the
 * footer because wp-admin never takes the redirect fallback, so nothing here
 * needs to happen before output. Records `admin_page` or `throttled` as the
 * skip reason for an eligible member the trigger stood down for.
 */
function agend_apps_saml_link_render_admin_placeholder(): void {
	if ( wp_doing_cron() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	try {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REQUEST_URI is a server-set path/query, not user POST data; only its PATH component is used below, and only for a marker-string comparison, never output.
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key PRESENCE only (never a value), to detect a request that is itself part of the SAML round trip; never output or stored.
		if ( ! agend_apps_saml_link_request_eligible( $method, $path, $_GET ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key PRESENCE only, to detect an iframe or modal admin screen; never output or stored.
		if ( ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) || isset( $_GET['iframe'] ) ) {
			return;
		}

		$user_id  = get_current_user_id();
		$decision = agend_apps_saml_link_admin_decision( $user_id );

		if ( 'skip' === $decision['action'] ) {
			if ( 'admin_page' === $decision['reason'] ) {
				agend_apps_saml_link_record_skip_if_eligible( $user_id, 'admin_page' );
			} elseif ( 'throttled' === $decision['reason'] ) {
				agend_apps_saml_link_record_skip( $user_id, 'throttled' );
			}

			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- agend_apps_saml_link_placeholder_markup() escapes the endpoint URL internally (esc_url()) and everything else is fixed, static markup.
		echo agend_apps_saml_link_emit_placeholder( $user_id, rest_url( 'agend-apps/v1/identity-link/sso-url' ), true );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link admin trigger failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'admin_footer', 'agend_apps_saml_link_render_admin_placeholder' );
