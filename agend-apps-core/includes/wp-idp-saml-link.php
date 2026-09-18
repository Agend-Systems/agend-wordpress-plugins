<?php
/**
 * WordPress-as-IdP identity link via a SAML round trip.
 *
 * An Agend identity may only be created from a signed SAML assertion (the
 * server-to-server link this plugin used to offer, `includes/wp-idp-link.php`'s
 * former `agend_apps_wp_idp_link_user()`, is retired). This file is what makes
 * `wordpress` sign-in mode actually link a member when the resolved mechanism
 * is `saml` (`Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML`): it passes a
 * freshly-signed-in member through the site's own SAML identity provider
 * plugin (agend-saml-idp) once, so the plugin's normal IdP-initiated flow
 * posts a real assertion to the gateway's ACS. The gateway then provisions
 * the user, records `sso_identities`, maps the role, creates the CRM contact,
 * and redirects back to this site because the RelayState origin matches the
 * connection's own IdP host -- all of that already works and is untouched by
 * this file.
 *
 * Two hops are required, not one, because of a WordPress cookie-timing fact:
 * at `login_redirect` time (fired from `wp_signon()` before it returns) the
 * auth cookies this request just set are not yet present in `$_COOKIE`. A
 * nonce created here (`wp_create_nonce()`) is bound to the current user via
 * that cookie, so a nonce minted at `login_redirect` and later verified by the
 * IdP plugin on a followed link would be computed against the WRONG (pre-
 * login) session and fail `wp_verify_nonce()`. So:
 *
 * 1. `login_redirect` (this file) sends the browser to a same-site handoff
 *    URL first -- a normal page load, by which point the cookies WordPress
 *    just set on this exact response are present.
 * 2. `template_redirect` on that handoff request (this file) builds the
 *    IdP-initiated SSO URL, WITH a nonce that is now correctly bound to the
 *    live session, and redirects into agend-saml-idp's own dispatcher.
 *
 * `login_redirect` alone is not enough to reach every member, because it only
 * fires for `wp_signon()`'s own default flow. Three cases never reach it:
 * WooCommerce's My Account login (which resolves its own redirect through
 * `woocommerce_login_redirect`, hooked below too, reusing the exact same
 * decision), an Elementor login widget that calls `wp_signon()` and redirects
 * the browser itself, and a member who already held a session before this
 * mechanism shipped (there was never a login event to hook at all). For all
 * three, `template_redirect` ALSO runs the same handoff decision implicitly,
 * with no query flag, on the very next ordinary front-end page view by a
 * logged-in, unlinked, not-throttled member -- see
 * {@see agend_apps_saml_link_implicit_trigger_eligible()} for exactly which
 * requests that excludes (anything not a plain front-end GET, and anything
 * that is itself part of the SAML round trip, so the implicit trigger cannot
 * interrupt or loop with the explicit one).
 *
 * Every hook here is guarded on `Agend_Apps_Settings::sso_link_mechanism()`
 * being `saml`, re-evaluated per request like the rest of the plugin: a site
 * on `disabled`, or one where `auto` currently resolves to `disabled` because
 * no SAML IdP plugin is detected, must never redirect a member through this
 * flow. Every hook wraps its work in `try`/`catch`, mirroring
 * `includes/wp-idp-link.php`'s former guarantee: a defect here must never
 * break a WordPress login or an ordinary page view.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query flag naming the handoff request: `home_url('/')` plus this flag set
 * to `1` and a `redirect_to` is the "come back here and get sent to the SAML
 * IdP" page the `login_redirect` decision below builds, and the URL
 * `template_redirect` below recognises.
 *
 * @var string
 */
const AGEND_APPS_SAML_LINK_QUERY_FLAG = 'agend_apps_saml_link';

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
 * Falls back to constructing the expected shape directly
 * (`{root}/api/auth/sso/{slug}/metadata`) when agend-saml-idp's registry
 * class is unavailable or holds no matching entry but an account slug and
 * root URL are both configured -- the registry not yet reflecting a
 * connection that otherwise exists is exactly the gap this covers.
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

	if ( '' === $result ) {
		$slug = method_exists( 'Agend_Apps_Settings', 'get_account_slug' ) ? Agend_Apps_Settings::get_account_slug() : '';
		$root = method_exists( 'Agend_Apps_Settings', 'get_root_url' ) ? Agend_Apps_Settings::get_root_url() : '';

		if ( '' !== $slug && '' !== $root ) {
			$result = rtrim( $root, '/' ) . '/api/auth/sso/' . rawurlencode( $slug ) . '/metadata';
		}
	}

	/**
	 * Filters the Agend SP entity id resolved for the SAML link handoff.
	 *
	 * @param string $result Resolved entity id, or '' when none resolved.
	 */
	return (string) apply_filters( 'agend_apps_saml_agend_sp_entity_id', $result );
}

/**
 * The `login_redirect` filter's pure decision: whether this sign-in should be
 * routed through the same-site handoff page before reaching its normal
 * destination.
 *
 * Returns the original `$redirect_to` unchanged (the normal case, on every
 * sign-in once a member is linked) unless the mechanism is `saml` AND the
 * member's recorded state is neither `linked` nor inside its backoff window.
 *
 * @param string $redirect_to WordPress's own resolved post-login redirect.
 * @param int    $user_id     The signed-in WordPress user id.
 * @return string The handoff URL, or `$redirect_to` unchanged.
 */
function agend_apps_saml_login_redirect_decision( string $redirect_to, int $user_id ): string {
	if ( Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML !== Agend_Apps_Settings::sso_link_mechanism() ) {
		return $redirect_to;
	}

	if ( 0 === $user_id ) {
		return $redirect_to;
	}

	$stored = agend_apps_wp_idp_link_state( $user_id );

	if ( AGEND_APPS_LINK_STATE_LINKED === $stored['state'] ) {
		return $redirect_to;
	}

	if ( agend_apps_wp_idp_link_is_throttled( $stored ) ) {
		return $redirect_to;
	}

	return add_query_arg(
		array(
			AGEND_APPS_SAML_LINK_QUERY_FLAG => '1',
			'redirect_to'                   => rawurlencode( $redirect_to ),
		),
		home_url( '/' )
	);
}

/**
 * `login_redirect` filter: the thin hook wrapper around
 * {@see agend_apps_saml_login_redirect_decision()}.
 *
 * Priority 50, after every other `login_redirect` filter has decided the
 * member's real post-login destination -- this needs that final value as the
 * `redirect_to` the handoff page returns to, not an earlier candidate.
 *
 * @param string                 $redirect_to           WordPress's resolved redirect.
 * @param string                 $requested_redirect_to Unused; required by the hook signature.
 * @param WP_User|WP_Error|mixed $user                  The signed-in user, or an error.
 * @return string
 */
function agend_apps_saml_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	unset( $requested_redirect_to );

	if ( ! ( $user instanceof WP_User ) ) {
		return $redirect_to;
	}

	try {
		return agend_apps_saml_login_redirect_decision( (string) $redirect_to, $user->ID );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML login handoff decision failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		return $redirect_to;
	}
}
add_filter( 'login_redirect', 'agend_apps_saml_login_redirect', 50, 3 );

/**
 * `woocommerce_login_redirect` filter: the same handoff decision as
 * {@see agend_apps_saml_login_redirect()}, for WooCommerce's My Account login
 * form, which resolves its own post-login redirect through this filter
 * instead of `login_redirect`. `add_filter()` registering against a hook a
 * site's plugins never fire is harmless -- WooCommerce not being active just
 * means this filter is never called -- so it is added unconditionally rather
 * than behind a `class_exists( 'WooCommerce' )` guard.
 *
 * @param string  $redirect WooCommerce's resolved redirect.
 * @param WP_User $user     The signed-in user.
 * @return string
 */
function agend_apps_saml_woocommerce_login_redirect( $redirect, $user ) {
	if ( ! ( $user instanceof WP_User ) ) {
		return $redirect;
	}

	try {
		return agend_apps_saml_login_redirect_decision( (string) $redirect, $user->ID );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] WooCommerce SAML login handoff decision failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		return $redirect;
	}
}
add_filter( 'woocommerce_login_redirect', 'agend_apps_saml_woocommerce_login_redirect', 50, 2 );

/**
 * The `template_redirect` handoff's pure decision.
 *
 * Resolves the Agend SP entity id, validates the return URL, and decides
 * between an error stand-down (no SP registered -- never loop: the `error`
 * state's backoff keeps this from being retried on every load) and a
 * redirect into agend-saml-idp's IdP-initiated flow. Records the `asserted`
 * state as a side effect immediately before returning the redirect, so the
 * round trip is never attempted twice for the same request even if the
 * caller's own redirect is somehow delayed. Performs NO redirect itself.
 *
 * Two callers share this one decision. With the query flag present (the
 * explicit handoff `login_redirect` built), the return target is
 * `$query['redirect_to']`. Without it (the implicit trigger on an ordinary
 * front-end page view -- see the file docblock), the return target is
 * `$current_url`, the page the member was already on: the round trip sends
 * them right back to where they were, rather than to a login destination
 * that has nothing to do with an already-established session.
 *
 * @param int    $user_id     Current WordPress user id (0 = signed out).
 * @param array  $query       The relevant `$_GET` values: `AGEND_APPS_SAML_LINK_QUERY_FLAG`
 *                             and `redirect_to`, both raw/unvalidated.
 * @param string $current_url The current request's URL, used as the return
 *                             target only when `$query` carries no flag.
 *                             Ignored when the flag is present. Raw/unvalidated.
 * @return array{action: string, url: string, state: string} `action` is
 *         `redirect` or `skip`; `url` and `state` are only meaningful when
 *         `action` is `redirect`.
 */
function agend_apps_saml_link_handoff_decision( int $user_id, array $query, string $current_url = '' ): array {
	$skip = array(
		'action' => 'skip',
		'url'    => '',
		'state'  => '',
	);

	$has_flag = isset( $query[ AGEND_APPS_SAML_LINK_QUERY_FLAG ] ) && '1' === (string) $query[ AGEND_APPS_SAML_LINK_QUERY_FLAG ];

	// Neither the explicit handoff nor the implicit trigger applies: nothing
	// to do, and nowhere to send the member back to even if there were.
	if ( ! $has_flag && '' === $current_url ) {
		return $skip;
	}

	if ( 0 === $user_id ) {
		return $skip;
	}

	if ( Agend_Apps_Settings::SSO_LINK_MECHANISM_SAML !== Agend_Apps_Settings::sso_link_mechanism() ) {
		return $skip;
	}

	$stored = agend_apps_wp_idp_link_state( $user_id );

	if ( AGEND_APPS_LINK_STATE_LINKED === $stored['state'] ) {
		return $skip;
	}

	if ( agend_apps_wp_idp_link_is_throttled( $stored ) ) {
		return $skip;
	}

	$raw_redirect  = $has_flag ? ( isset( $query['redirect_to'] ) ? (string) $query['redirect_to'] : '' ) : $current_url;
	$safe_redirect = wp_validate_redirect( $raw_redirect, home_url( '/' ) );

	$entity = agend_apps_saml_agend_sp_entity_id();

	if ( '' === $entity ) {
		agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_ERROR, 'sp_not_registered' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link handoff failed for user ' . $user_id . ': no Agend SP registered with the SAML IdP plugin.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		return array(
			'action' => 'redirect',
			'url'    => $safe_redirect,
			'state'  => AGEND_APPS_LINK_STATE_ERROR,
		);
	}

	// `sp` and `RelayState` are rawurlencode()'d here, before add_query_arg():
	// WordPress's real add_query_arg() does not encode its values itself
	// (`_http_build_query( ..., false )`), so this is the single point of
	// encoding on the wire, matching the convention
	// includes/account-link-state.php already uses. `sanitize_text_field(
	// wp_unslash( $_GET['sp'] ) )` on the receiving (IdP) side then yields the
	// exact entity id back.
	$idp_url = add_query_arg(
		array(
			'idp_initiated' => '1',
			'sp'            => rawurlencode( $entity ),
			'RelayState'    => rawurlencode( $safe_redirect ),
			'_wpnonce'      => wp_create_nonce( 'wp_saml_idp_sso_' . $entity ),
		),
		home_url( '/' )
	);

	agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_ASSERTED );

	if ( class_exists( 'Agend_Apps_Token_Worker' ) ) {
		Agend_Apps_Token_Worker::clear_negative_cache( $user_id );
	}

	return array(
		'action' => 'redirect',
		'url'    => $idp_url,
		'state'  => AGEND_APPS_LINK_STATE_ASSERTED,
	);
}

/**
 * Whether the CURRENT request may carry the implicit handoff trigger (no
 * query flag; see the file docblock and {@see agend_apps_saml_link_handoff_decision()}).
 *
 * Deliberately excludes anything that is not an ordinary front-end page
 * view -- a non-GET request (a form submit must not be redirected mid-
 * submission), and every shape of request that is itself PART of the SAML
 * round trip this file drives or that agend-saml-idp's own dispatcher
 * handles (`saml`, `idp_initiated`, `SAMLRequest`, `saml_action`, `option` --
 * agend-saml-idp reads its admin/dispatch action from one of these
 * depending on entry point -- and the `/saml/` path prefix some SAML
 * plugins route through, plus wp-login.php itself, which `login_redirect`
 * and `woocommerce_login_redirect` already cover). Without these exclusions
 * the implicit trigger could interrupt the round trip it is meant to start,
 * or loop with it.
 *
 * @param string $method Request method, e.g. `$_SERVER['REQUEST_METHOD']`.
 * @param string $path   Request path only (no query string), e.g. from
 *                       `wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH )`.
 * @param array  $query  The current request's `$_GET` (only key PRESENCE is
 *                       read; values are never used).
 * @return bool
 */
function agend_apps_saml_link_implicit_trigger_eligible( string $method, string $path, array $query ): bool {
	if ( 'GET' !== $method ) {
		return false;
	}

	if ( false !== strpos( $path, 'wp-login.php' ) || 0 === strpos( $path, '/saml/' ) ) {
		return false;
	}

	foreach ( array( 'saml', 'idp_initiated', 'SAMLRequest', 'saml_action', 'option' ) as $marker ) {
		if ( isset( $query[ $marker ] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * `template_redirect` handler: the thin hook wrapper around
 * {@see agend_apps_saml_link_handoff_decision()}.
 *
 * Cheap by construction for every request that is not this exact handoff:
 * `is_admin()`, cron, REST, and AJAX requests stop here immediately (none of
 * them can carry a browser session through a redirect chain anyway), then a
 * signed-out visitor. From there the query flag decides which of the two
 * paths above runs: with it, the explicit handoff (unconditional on request
 * shape -- it is a redirect target this file itself built); without it, the
 * implicit trigger, gated additionally on
 * {@see agend_apps_saml_link_implicit_trigger_eligible()} so it only ever
 * fires on a plain front-end page view.
 */
function agend_apps_saml_link_handoff(): void {
	if ( is_admin() || wp_doing_cron() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		return;
	}

	try {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag/redirect read; the security boundary is wp_validate_redirect() on the value plus the nonce agend-saml-idp verifies on the next hop, not a nonce on this one.
		$flag = isset( $_GET[ AGEND_APPS_SAML_LINK_QUERY_FLAG ] ) ? sanitize_text_field( wp_unslash( $_GET[ AGEND_APPS_SAML_LINK_QUERY_FLAG ] ) ) : '';

		if ( '1' === $flag ) {
			$query = array(
				AGEND_APPS_SAML_LINK_QUERY_FLAG => $flag,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
				'redirect_to'                   => isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '',
			);

			$decision = agend_apps_saml_link_handoff_decision( get_current_user_id(), $query );
		} else {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- REQUEST_URI is a server-set path/query, not user POST data; only its PATH component is used below, and only for a marker-string comparison, never output.
			$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- key PRESENCE only (never a value), to detect a request that is itself part of the SAML round trip; never output or stored.
			if ( ! agend_apps_saml_link_implicit_trigger_eligible( $method, $path, $_GET ) ) {
				return;
			}

			$current_url = home_url( add_query_arg( array() ) );

			$decision = agend_apps_saml_link_handoff_decision( get_current_user_id(), array(), $current_url );
		}

		if ( 'redirect' === $decision['action'] && '' !== $decision['url'] ) {
			wp_safe_redirect( $decision['url'] );
			exit;
		}
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] SAML link handoff failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'template_redirect', 'agend_apps_saml_link_handoff' );
