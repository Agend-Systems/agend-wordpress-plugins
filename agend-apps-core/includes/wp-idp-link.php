<?php
/**
 * WordPress-as-IdP identity link state.
 *
 * The server-to-server link step this file used to run
 * (`agend_apps_wp_idp_link_user()`, posting `POST /v1/sso/identities` with no
 * signed assertion) is retired: an Agend identity may only be created from a
 * signed SAML assertion. Linking a WordPress member to Agend now happens
 * through the SAML round trip in `includes/wp-idp-saml-link.php`, which loads
 * after this file (it reuses the state helpers below) and is the only file
 * that writes a link state forward from a WordPress sign-in.
 *
 * What remains here is the shared vocabulary every surface that talks about a
 * member's link state depends on: the state constants, the per-user recorded
 * state (read/write), and the per-state backoff/throttle so a repeatedly
 * failing state cannot be retried on every page load. `includes/wp-idp-
 * diagnostics.php` (loaded unconditionally) and `includes/wp-idp-saml-link.php`
 * (loaded only in `wordpress` sign-in mode, alongside this file) both depend
 * on these.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link state: the pair (idp_entity_id, external_id) is bound to an Agend
 * user, confirmed by the gateway (a successful token mint, or a status poll
 * reporting linked).
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_LINKED = 'linked';

/**
 * Link state: the gateway withheld the link (202 `verification_required`).
 * Nothing was created; a confirm email is outstanding.
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_PENDING = 'pending';

/**
 * Link state: the resolved user already holds a DIFFERENT external id on
 * this connection (409 `IDENTITY_ALREADY_LINKED`). The gateway never
 * re-points a link automatically; this needs a human in the dashboard.
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_CONFLICT = 'conflict';

/**
 * Link state: no CRM contact matches the email and the connection has JIT
 * contact provisioning switched off (404 `CONTACT_NOT_FOUND`). A CRM or
 * connection configuration problem, not something a retry fixes on its own.
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_NO_CONTACT = 'no_contact';

/**
 * Link state: no SSO connection matches this site's `idp_entity_id` and the
 * key lacks `sso.connections.create` to auto-create one (403
 * `CONNECTION_CREATE_FORBIDDEN`). A dashboard configuration problem.
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_FORBIDDEN = 'forbidden';

/**
 * Link state: a transport failure, an unrecognised gateway error, or a 5xx.
 * Retryable -- the next throttle window tries again without help.
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_ERROR = 'error';

/**
 * Link state: a SAML assertion was sent to Agend for this member
 * (`includes/wp-idp-saml-link.php`'s handoff redirected them through the
 * site's SAML identity provider); the link is confirmed once a token mint
 * succeeds (`Agend_Apps_Token_Worker::provide_token()` promotes this to
 * `linked`). Recorded so a member whose round trip has not yet completed --
 * or whose IdP declined it silently -- is not handed the handoff redirect
 * again on every single sign-in.
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_ASSERTED = 'asserted';

/**
 * User-meta key holding the recorded link state
 * `array{state: string, error_code: string, timestamp: int}`.
 * Underscore-prefixed like the other identity meta in `includes/identity.php`
 * -- hidden from the profile UI, never sent to the browser.
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_META = '_agend_apps_link_state';

/**
 * Per-user backoff while `pending`. Deliberately SHORT.
 *
 * The long-backoff reasoning (avoid rotating the pending confirm token, and
 * avoid triggering another email) is about the WRITE endpoint, and a pending
 * user never reaches it: the only call this state makes is the read-only
 * `GET /v1/sso/identities/status`, which mints nothing, writes nothing and
 * sends no email. Applying the write path's caution to the poll would buy
 * nothing and cost the member real access.
 *
 * What it would cost: the member has just clicked the confirmation link and
 * come back, so this is the exact moment the site must notice they are
 * linked. Until it does, `agend-content-access` fails closed on the empty
 * bearer and they are shown less than they were before they confirmed. This
 * window is the upper bound on how long that can last.
 *
 * @var int
 */
const AGEND_APPS_LINK_BACKOFF_PENDING = 5 * MINUTE_IN_SECONDS;

/**
 * Per-user backoff after `conflict`, `no_contact`, or `forbidden`: all three
 * are configuration or human problems (a dashboard re-point, a missing CRM
 * contact, a missing connection) that a login-triggered retry cannot fix, so
 * the backoff is long rather than "on the order of" anything operational.
 *
 * @var int
 */
const AGEND_APPS_LINK_BACKOFF_HUMAN = 24 * HOUR_IN_SECONDS;

/**
 * Per-user backoff after `error` (transport failure, an unrecognised gateway
 * code, or a 5xx): short, because these are the outcomes most likely to
 * clear themselves on the very next login.
 *
 * @var int
 */
const AGEND_APPS_LINK_BACKOFF_ERROR = 5 * MINUTE_IN_SECONDS;

/**
 * Per-user backoff after `asserted`: short, for the same reason as `error` --
 * a member whose SAML round trip has not yet resolved (they abandoned it, the
 * IdP declined it, the token mint has not run yet) should be offered the
 * handoff again soon, not made to wait a human-scale window, but not on
 * every single sign-in either.
 *
 * @var int
 */
const AGEND_APPS_LINK_BACKOFF_ASSERTED = 5 * MINUTE_IN_SECONDS;

/**
 * Reads a WordPress user's recorded Agend link state.
 *
 * Read-only accessor for any surface that wants to display the state (the
 * diagnostics panel, `includes/wp-idp-diagnostics.php`). Never triggers a
 * gateway call.
 *
 * @param int $user_id WordPress user id.
 * @return array{state: string, error_code: string, timestamp: int} Defaults
 *         to an empty state ('', '', 0) when nothing has been recorded.
 */
function agend_apps_wp_idp_link_state( int $user_id ): array {
	$default = array(
		'state'      => '',
		'error_code' => '',
		'timestamp'  => 0,
	);

	if ( 0 === $user_id ) {
		return $default;
	}

	$stored = get_user_meta( $user_id, AGEND_APPS_LINK_STATE_META, true );

	if ( ! is_array( $stored ) ) {
		return $default;
	}

	return array(
		'state'      => isset( $stored['state'] ) ? (string) $stored['state'] : '',
		'error_code' => isset( $stored['error_code'] ) ? (string) $stored['error_code'] : '',
		'timestamp'  => isset( $stored['timestamp'] ) ? (int) $stored['timestamp'] : 0,
	);
}

/**
 * Records a WordPress user's link state and stamps the attempt time.
 *
 * @param int    $user_id    WordPress user id.
 * @param string $state      One of the `AGEND_APPS_LINK_STATE_*` constants.
 * @param string $error_code A machine-readable reason code (e.g.
 *                           `IDENTITY_ALREADY_LINKED`, `sp_not_registered`),
 *                           or '' when the state carries none (linked/pending/asserted).
 */
function agend_apps_wp_idp_record_link_state( int $user_id, string $state, string $error_code = '' ): void {
	update_user_meta(
		$user_id,
		AGEND_APPS_LINK_STATE_META,
		array(
			'state'      => $state,
			'error_code' => $error_code,
			'timestamp'  => time(),
		)
	);
}

/**
 * The per-user throttle window for a given non-linked state.
 *
 * @param string $state One of the `AGEND_APPS_LINK_STATE_*` constants (or '').
 * @return int Seconds. 0 means "no throttle, always attempt" (an empty/never-attempted
 *             state, or an unrecognised one).
 */
function agend_apps_wp_idp_link_backoff_seconds( string $state ): int {
	switch ( $state ) {
		case AGEND_APPS_LINK_STATE_PENDING:
			return AGEND_APPS_LINK_BACKOFF_PENDING;

		case AGEND_APPS_LINK_STATE_CONFLICT:
		case AGEND_APPS_LINK_STATE_NO_CONTACT:
		case AGEND_APPS_LINK_STATE_FORBIDDEN:
			return AGEND_APPS_LINK_BACKOFF_HUMAN;

		case AGEND_APPS_LINK_STATE_ERROR:
			return AGEND_APPS_LINK_BACKOFF_ERROR;

		case AGEND_APPS_LINK_STATE_ASSERTED:
			return AGEND_APPS_LINK_BACKOFF_ASSERTED;

		default:
			return 0;
	}
}

/**
 * Whether the last recorded attempt is still inside its backoff window.
 *
 * @param array{state: string, error_code: string, timestamp: int} $stored Recorded state.
 * @return bool
 */
function agend_apps_wp_idp_link_is_throttled( array $stored ): bool {
	if ( '' === $stored['state'] ) {
		return false;
	}

	$backoff = agend_apps_wp_idp_link_backoff_seconds( $stored['state'] );

	if ( 0 === $backoff ) {
		return false;
	}

	return ( time() - $stored['timestamp'] ) < $backoff;
}

/**
 * `user_register` handler: mints the external id (so every WordPress user has
 * one from the moment they exist, per {@see agend_apps_ensure_external_id()}).
 *
 * The link-on-create attempt this handler used to make (server-to-server) is
 * retired along with the mechanism it drove: there is no assertion-free way
 * to create an Agend identity from a `user_register` event, so this handler
 * is now only the external-id mint every WordPress user needs regardless of
 * sign-in mode.
 *
 * @param int $user_id The newly-created WordPress user id.
 */
function agend_apps_wp_idp_handle_user_register( int $user_id ): void {
	try {
		agend_apps_ensure_external_id( $user_id );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] user_register WordPress-IdP handler failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'user_register', 'agend_apps_wp_idp_handle_user_register' );
