<?php
/**
 * WordPress-as-IdP identity link step.
 *
 * docs/PLAN-wordpress-idp-option-b.md section 4.2, with the shipped gateway
 * contract (see the build brief this file was written against) superseding
 * that document's draft `/v1/sso/identities` shape where the two disagree.
 *
 * Binds a WordPress user to an Agend identity server to server, using the
 * account-scoped API key -- no password ever crosses the boundary. Loaded
 * only in `wordpress` sign-in mode (`Agend_Apps_Settings::wordpress_idp_enabled()`),
 * alongside the other conditional requires in `agend-apps-core.php`.
 *
 * Two operational facts drive the throttling below:
 *
 * 1. Re-posting `POST /v1/sso/identities` while a withhold (202
 *    `verification_required`) is pending ROTATES the pending confirm token
 *    and may send the member another email (the gateway rate-limits that per
 *    mailbox per hour, but this plugin must not rely on the gateway alone to
 *    avoid spamming a member). So a `pending` user is never re-posted; the
 *    read-only `GET /v1/sso/identities/status` is polled instead.
 * 2. There is no webhook and no push when a pending member completes
 *    verification, so polling on a later login is the only way this plugin
 *    learns about it.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Link state: the pair (idp_entity_id, external_id) is bound to an Agend
 * user, confirmed by the gateway (201/200, or a status poll reporting
 * linked).
 *
 * @var string
 */
const AGEND_APPS_LINK_STATE_LINKED = 'linked';

/**
 * Link state: the gateway withheld the link (202 `verification_required`).
 * Nothing was created; a confirm email is outstanding. Never re-posted --
 * only polled (see the file docblock).
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
 * Reads a WordPress user's recorded Agend link state.
 *
 * Read-only accessor for any surface that wants to display the state (a
 * future diagnostic panel, per docs/PLAN-wordpress-idp-option-b.md section
 * 6 -- not built by this file). Never triggers a gateway call.
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
 * @param string $error_code The gateway error code (e.g. `IDENTITY_ALREADY_LINKED`),
 *                           or '' when the state carries none (linked/pending).
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
 * Maps a gateway error from `agend_apps_sso_link_identity()` to a link state.
 *
 * @param WP_Error $error Gateway error.
 * @return string One of the `AGEND_APPS_LINK_STATE_*` constants (never '').
 */
function agend_apps_wp_idp_link_state_for_error( WP_Error $error ): string {
	$status = agend_apps_auth_error_status( $error );
	$code   = agend_apps_auth_error_code( $error );

	if ( 409 === $status && 'IDENTITY_ALREADY_LINKED' === $code ) {
		return AGEND_APPS_LINK_STATE_CONFLICT;
	}

	if ( 404 === $status && 'CONTACT_NOT_FOUND' === $code ) {
		return AGEND_APPS_LINK_STATE_NO_CONTACT;
	}

	if ( 403 === $status && 'CONNECTION_CREATE_FORBIDDEN' === $code ) {
		return AGEND_APPS_LINK_STATE_FORBIDDEN;
	}

	// Everything else -- transport failure (status 0), rate limiting,
	// validation errors that should never happen given how the payload is
	// built, other 403/401 (a scope problem the feature gate below should
	// already have caught, but the gateway is the final authority), and any
	// 5xx -- is retryable. An unrecognised code is deliberately treated as
	// retryable rather than as a hard stop: standing down forever on a
	// gateway answer this plugin does not understand would be worse than
	// trying again on the next login.
	return AGEND_APPS_LINK_STATE_ERROR;
}

/**
 * Polls `GET /v1/sso/identities/status` for a user already in the `pending`
 * state, promoting to `linked` when the gateway reports it. Never re-posts
 * the identity (see the file docblock) -- this is the only network call a
 * pending user's login triggers.
 *
 * @param int    $user_id       WordPress user id.
 * @param string $idp_entity_id This site's SSO connection entity id.
 * @param string $external_id   The member's external id.
 * @return string The resulting state constant.
 */
function agend_apps_wp_idp_poll_pending_link( int $user_id, string $idp_entity_id, string $external_id ): string {
	$response = agend_apps_sso_get_link_status( $idp_entity_id, $external_id );

	if ( is_wp_error( $response ) ) {
		// The poll itself failing does not un-pend the member -- it just
		// means this attempt learned nothing. Stay pending (not `error`):
		// re-classifying a transient status-check failure as `error` would
		// give it the SHORT backoff, defeating the whole point of the long
		// `pending` window (avoiding token rotation on the write endpoint,
		// which this poll never touches anyway).
		agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_PENDING );
		return AGEND_APPS_LINK_STATE_PENDING;
	}

	$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

	if ( is_array( $data ) && ! empty( $data['linked'] ) ) {
		agend_apps_record_linked_identity( $user_id, $data );
		agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_LINKED );
		Agend_Apps_Token_Worker::clear_negative_cache( $user_id );

		return AGEND_APPS_LINK_STATE_LINKED;
	}

	agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_PENDING );

	return AGEND_APPS_LINK_STATE_PENDING;
}

/**
 * Links (or re-checks the link for) a WordPress user, server to server.
 *
 * The single entry point for the whole link step
 * (docs/PLAN-wordpress-idp-option-b.md section 4.2). Safe to call on every
 * login: it no-ops immediately outside `server` mechanism, short-circuits
 * once linked, and throttles every other state per
 * {@see agend_apps_wp_idp_link_backoff_seconds()} so a burst of logins can
 * never hammer the gateway or, worse, rotate a pending member's
 * verification token by re-posting.
 *
 * Never throws: every path that can fail (the gateway call, the feature
 * checks, anything a filter callback hooked onto a helper here might do) is
 * inside the `try`/`catch` below, because a gateway problem must NEVER
 * delay or break a WordPress login.
 *
 * @param int $user_id WordPress user id.
 * @return string One of the `AGEND_APPS_LINK_STATE_*` constants, or '' when
 *                the site is not using the `server` link mechanism (SAML
 *                sites and disabled sites must never call this endpoint).
 */
function agend_apps_wp_idp_link_user( int $user_id ): string {
	if ( Agend_Apps_Settings::SSO_LINK_MECHANISM_SERVER !== Agend_Apps_Settings::sso_link_mechanism() ) {
		return '';
	}

	if ( 0 === $user_id ) {
		return '';
	}

	try {
		$stored = agend_apps_wp_idp_link_state( $user_id );

		if ( AGEND_APPS_LINK_STATE_LINKED === $stored['state'] ) {
			return AGEND_APPS_LINK_STATE_LINKED;
		}

		if ( agend_apps_wp_idp_link_is_throttled( $stored ) ) {
			return $stored['state'];
		}

		// The sso_identity_link optional feature (includes/records/features.php):
		// a key without sso.identities.create always gets a 403 from
		// POST /v1/sso/identities, so never attempt the call at all. Mirrors
		// Agend_Apps_Token_Worker::provide_token()'s stand-down for
		// sso_account_link. Deliberately does not persist a state change or
		// consume the throttle window: this is a local, static fact about the
		// connected key, not a gateway answer, and re-checking the gateway
		// scopes on the very next login (once the key is fixed) must not be
		// blocked by a backoff this stand-down never set.
		if ( function_exists( 'agend_apps_records_feature_available' ) && ! agend_apps_records_feature_available( 'sso_identity_link' ) ) {
			return $stored['state'];
		}

		$idp_entity_id = agend_apps_idp_entity_id();
		$external_id   = agend_apps_ensure_external_id( $user_id );

		if ( '' === $external_id ) {
			return $stored['state'];
		}

		if ( AGEND_APPS_LINK_STATE_PENDING === $stored['state'] ) {
			return agend_apps_wp_idp_poll_pending_link( $user_id, $idp_entity_id, $external_id );
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! ( $user instanceof WP_User ) || '' === (string) $user->user_email ) {
			agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_ERROR );
			return AGEND_APPS_LINK_STATE_ERROR;
		}

		$contact = array_filter(
			array(
				'first_name' => (string) $user->first_name,
				'last_name'  => (string) $user->last_name,
			),
			static function ( $value ) {
				return '' !== $value;
			}
		);

		$response = agend_apps_sso_link_identity( $idp_entity_id, $external_id, (string) $user->user_email, $contact );

		if ( is_wp_error( $response ) ) {
			$state      = agend_apps_wp_idp_link_state_for_error( $response );
			$error_code = agend_apps_auth_error_code( $response );

			agend_apps_wp_idp_record_link_state( $user_id, $state, $error_code );

			return $state;
		}

		$data = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;

		if ( is_array( $data ) && isset( $data['status'] ) && 'verification_required' === $data['status'] ) {
			// 202: WITHHELD. Nothing created -- record pending and stop. No
			// ids to store; the confirm link, when followed, completes the
			// link on the gateway's side with no push back to this site, so
			// only a later poll (above) learns about it.
			agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_PENDING );

			return AGEND_APPS_LINK_STATE_PENDING;
		}

		// 201 (created) or 200 (idempotent re-post): linked either way.
		agend_apps_record_linked_identity( $user_id, is_array( $data ) ? $data : array() );
		agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_LINKED );
		Agend_Apps_Token_Worker::clear_negative_cache( $user_id );

		return AGEND_APPS_LINK_STATE_LINKED;
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] WordPress-IdP link step failed for user ' . $user_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		agend_apps_wp_idp_record_link_state( $user_id, AGEND_APPS_LINK_STATE_ERROR );

		return AGEND_APPS_LINK_STATE_ERROR;
	}
}

/**
 * `wp_login` handler: the safety-net trigger for the link step, mirroring
 * `agend-entitlement-mirror/includes/class-entitlement-sync.php:182-207`'s
 * `handle_login()` (throttle plus non-blocking `try`/`catch`). Priority 20,
 * same as that mirror hook, so both run after WordPress has fully resolved
 * the login (priority 10 is the default `wp_signon()` uses internally) but
 * independently of each other.
 *
 * `agend_apps_wp_idp_link_user()` already carries its own `try`/`catch` and
 * per-user backoff, so this handler is a thin, doubly-safe wrapper: even a
 * defect in a hook this file itself hangs off (a sibling plugin's filter
 * callback throwing, say) cannot escape here and break authentication.
 *
 * @param string  $user_login Unused; required by the `wp_login` hook signature.
 * @param WP_User $user       The user who just logged in.
 */
function agend_apps_wp_idp_handle_login( string $user_login, WP_User $user ): void {
	unset( $user_login );

	try {
		agend_apps_wp_idp_link_user( $user->ID );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] wp_login WordPress-IdP link hook failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'wp_login', 'agend_apps_wp_idp_handle_login', 20, 2 );

/**
 * `user_register` handler: always mints the external id (so every WordPress
 * user has one from the moment they exist, per
 * {@see agend_apps_ensure_external_id()}), but only attempts the link
 * itself when {@see Agend_Apps_Settings::link_on_user_create()} is on.
 *
 * Off by default because `user_register` fires for every new WordPress
 * user, including administrators and spam registrations, which a site may
 * not want posted to the gateway automatically.
 *
 * @param int $user_id The newly-created WordPress user id.
 */
function agend_apps_wp_idp_handle_user_register( int $user_id ): void {
	try {
		agend_apps_ensure_external_id( $user_id );

		if ( Agend_Apps_Settings::link_on_user_create() ) {
			agend_apps_wp_idp_link_user( $user_id );
		}
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] user_register WordPress-IdP handler failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'user_register', 'agend_apps_wp_idp_handle_user_register' );

/**
 * `init` handler: the lazy poll that closes the withhold round trip
 * (docs/PLAN-wordpress-idp-option-b.md section 4.2, "a lazy fallback when a
 * bearer is needed and no link state exists").
 *
 * `wp_login` alone is not enough for a `pending` member, and the gap is the
 * worst one in the flow. The sequence is: sign in, get withheld, receive the
 * email, click the confirm link, come back to the site. That return trip
 * fires no `wp_login`, because the WordPress session never ended. Without
 * this hook the member would have to log out and back in before the site
 * noticed the link they were just told to complete, and until then
 * `agend-content-access` fails closed on the empty bearer and shows them
 * less than before they confirmed.
 *
 * Cheap by construction, in this order: the file is only loaded in
 * `wordpress` mode at all; signed-out requests and cron stop here; the state
 * read is one usermeta hit; and only the `pending` state proceeds. The
 * backoff inside `agend_apps_wp_idp_link_user()` then bounds an actual
 * gateway call to once per {@see AGEND_APPS_LINK_BACKOFF_PENDING}. Every
 * other state is left entirely to `wp_login`, so a linked, conflicted or
 * errored member costs nothing but that usermeta read.
 */
function agend_apps_wp_idp_maybe_poll_pending(): void {
	if ( ! is_user_logged_in() || wp_doing_cron() ) {
		return;
	}

	try {
		$user_id = get_current_user_id();

		if ( AGEND_APPS_LINK_STATE_PENDING !== agend_apps_wp_idp_link_state( $user_id )['state'] ) {
			return;
		}

		agend_apps_wp_idp_link_user( $user_id );
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Agend Apps] pending-link poll failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
add_action( 'init', 'agend_apps_wp_idp_maybe_poll_pending' );
