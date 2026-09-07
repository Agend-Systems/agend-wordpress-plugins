<?php
/**
 * Dashboard account provisioning for WordPress users.
 *
 * SPEC-CORE-20260907-wordpress-session-parity. Agend is the credential
 * authority for the site: a WordPress user with no dashboard account gets one
 * registered through the gateway, and the gateway's 409
 * `EMAIL_ALREADY_REGISTERED` is the only way this plugin learns that an email
 * already has one (SPEC-API-20260810 v1.1 Decision 2.10). That 409 is shown
 * verbosely on a registration surface only; every login surface collapses it
 * to the generic incorrect-credentials message.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User-meta flag: the email already had a dashboard account when this plugin
 * tried to register it. The user holds no member session and must sign in
 * with their Agend password (never a WordPress-only one).
 *
 * @var string
 */
const AGEND_APPS_IDENTITY_CONFLICT_META = '_agend_apps_identity_conflict';

/**
 * Outcome of a provisioning attempt.
 */
const AGEND_APPS_PROVISION_REGISTERED = 'registered';
const AGEND_APPS_PROVISION_CONFLICT   = 'conflict';
const AGEND_APPS_PROVISION_PENDING    = 'pending';
const AGEND_APPS_PROVISION_ERROR      = 'error';
const AGEND_APPS_PROVISION_SKIPPED    = 'skipped';

/**
 * Reads the upstream HTTP status from a gateway `WP_Error`.
 *
 * @param WP_Error $error Gateway error.
 * @return int Status code, or 0 when the error carries none (transport failure).
 */
function agend_apps_auth_error_status( WP_Error $error ): int {
	$data = $error->get_error_data();

	return ( is_array( $data ) && isset( $data['status_code'] ) ) ? (int) $data['status_code'] : 0;
}

/**
 * Reads the gateway error code (`error.code` in the response envelope) from a
 * gateway `WP_Error`.
 *
 * @param WP_Error $error Gateway error.
 * @return string Upper-case gateway code, or '' when absent.
 */
function agend_apps_auth_error_code( WP_Error $error ): string {
	$data = $error->get_error_data();

	if ( ! is_array( $data ) || ! isset( $data['body']['error']['code'] ) ) {
		return '';
	}

	return strtoupper( (string) $data['body']['error']['code'] );
}

/**
 * Reads the gateway error detail code (`error.details.code`) from a gateway
 * `WP_Error`. Some gateway errors carry their specific reason here under a
 * generic top-level code (a `BAD_REQUEST` whose detail is
 * `CONTACT_ALREADY_LINKED`).
 *
 * @param WP_Error $error Gateway error.
 * @return string Upper-case detail code, or '' when absent.
 */
function agend_apps_auth_error_detail_code( WP_Error $error ): string {
	$data = $error->get_error_data();

	if ( ! is_array( $data ) || ! isset( $data['body']['error']['details']['code'] ) ) {
		return '';
	}

	return strtoupper( (string) $data['body']['error']['details']['code'] );
}

/**
 * Whether a register error means the email already has a dashboard account.
 *
 * Two gateway answers say so. A 409 `EMAIL_ALREADY_REGISTERED` is the
 * platform-wide duplicate (the email has a login but no contact on this
 * account). A 400 whose detail is `CONTACT_ALREADY_LINKED` is the
 * account-scoped duplicate: the register pre-flight found a contact for the
 * email on this account already bound to a user, and refused before touching
 * auth. For this plugin both mean the same thing: the person must sign in
 * with their Agend password.
 *
 * @param WP_Error $error Gateway error from `agend_apps_auth_register()`.
 * @return bool
 */
function agend_apps_auth_error_is_email_conflict( WP_Error $error ): bool {
	$status = agend_apps_auth_error_status( $error );

	if ( 409 === $status && 'EMAIL_ALREADY_REGISTERED' === agend_apps_auth_error_code( $error ) ) {
		return true;
	}

	return 400 === $status && 'CONTACT_ALREADY_LINKED' === agend_apps_auth_error_detail_code( $error );
}

/**
 * Whether a login error is the gateway's collapsed invalid-credentials
 * response (wrong password, unknown email, or no contact on the account: the
 * gateway does not distinguish them, and neither may this plugin).
 *
 * @param WP_Error $error Gateway error from `agend_apps_auth_login()`.
 * @return bool
 */
function agend_apps_auth_error_is_invalid_credentials( WP_Error $error ): bool {
	return 401 === agend_apps_auth_error_status( $error );
}

/**
 * Extracts the session envelope from a decoded login or register response.
 *
 * @param array $response Decoded gateway response (with or without the `data` wrapper).
 * @return array{data: array, session: array} The unwrapped data and its session (empty when unusable).
 */
function agend_apps_auth_response_session( array $response ): array {
	$data    = ( isset( $response['data'] ) && is_array( $response['data'] ) ) ? $response['data'] : $response;
	$session = ( isset( $data['session'] ) && is_array( $data['session'] ) ) ? $data['session'] : array();

	if ( empty( $session['access_token'] ) || empty( $session['refresh_token'] ) ) {
		$session = array();
	}

	return array(
		'data'    => $data,
		'session' => $session,
	);
}

/**
 * Builds the gateway register payload for a WordPress user.
 *
 * @param string $email      Email address.
 * @param string $password   Plaintext password to register with.
 * @param string $first_name First name ('' to omit).
 * @param string $last_name  Last name ('' to omit).
 * @return array Register payload (snake_case, gateway shape).
 */
function agend_apps_provision_register_payload( string $email, string $password, string $first_name = '', string $last_name = '' ): array {
	$payload = array(
		'email'    => $email,
		'password' => $password,
	);

	$contact = array_filter(
		array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
		),
		static function ( $value ) {
			return '' !== $value;
		}
	);

	if ( ! empty( $contact ) ) {
		$payload['contact'] = $contact;
	}

	return $payload;
}

/**
 * Records the outcome of a provisioning attempt on the WordPress user.
 *
 * A stored session clears any earlier conflict flag; a conflict sets it. The
 * managed flag is never set here: managed means "created by this plugin", and
 * a pre-existing WordPress user (an administrator, say) must never have its
 * role rewritten from Agend because a dashboard account was registered for it.
 *
 * @param int    $user_id WordPress user id.
 * @param string $outcome One of the AGEND_APPS_PROVISION_* constants.
 * @param array  $data    Decoded register data (for contact and user refs).
 * @param array  $session Session envelope ('' fields when none).
 */
function agend_apps_provision_record_outcome( int $user_id, string $outcome, array $data = array(), array $session = array() ): void {
	if ( AGEND_APPS_PROVISION_CONFLICT === $outcome ) {
		update_user_meta( $user_id, AGEND_APPS_IDENTITY_CONFLICT_META, '1' );
		return;
	}

	if ( AGEND_APPS_PROVISION_REGISTERED !== $outcome ) {
		return;
	}

	delete_user_meta( $user_id, AGEND_APPS_IDENTITY_CONFLICT_META );
	Agend_Apps_Member_Session::store( $user_id, $session );
	Agend_Apps_Token_Worker::clear_negative_cache( $user_id );

	if ( function_exists( 'agend_apps_member_store_contact_ref' ) ) {
		agend_apps_member_store_contact_ref( $user_id, $data );
	}
}

/**
 * Registers a dashboard account for an existing WordPress user and stores the
 * resulting member session on it.
 *
 * @param int    $user_id    WordPress user id the outcome is recorded on.
 * @param string $email      Email to register.
 * @param string $password   Plaintext password to register with.
 * @param string $first_name First name ('' to omit).
 * @param string $last_name  Last name ('' to omit).
 * @return array{outcome: string, data: array, error: WP_Error|null} Outcome constant, decoded data on success, error otherwise.
 */
function agend_apps_provision_dashboard_account( int $user_id, string $email, string $password, string $first_name = '', string $last_name = '' ): array {
	$result = array(
		'outcome' => AGEND_APPS_PROVISION_ERROR,
		'data'    => array(),
		'error'   => null,
	);

	if ( 0 === $user_id || '' === $email || '' === $password ) {
		$result['outcome'] = AGEND_APPS_PROVISION_SKIPPED;
		return $result;
	}

	$response = agend_apps_auth_register(
		agend_apps_provision_register_payload( $email, $password, $first_name, $last_name )
	);

	if ( is_wp_error( $response ) ) {
		$result['error'] = $response;

		if ( agend_apps_auth_error_is_email_conflict( $response ) ) {
			$result['outcome'] = AGEND_APPS_PROVISION_CONFLICT;
			agend_apps_provision_record_outcome( $user_id, AGEND_APPS_PROVISION_CONFLICT );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				sprintf( '[Agend Apps] Provisioning failed for user %d (status %d).', $user_id, agend_apps_auth_error_status( $response ) )
			);
		}

		return $result;
	}

	$parsed         = agend_apps_auth_response_session( $response );
	$result['data'] = $parsed['data'];

	if ( empty( $parsed['session'] ) ) {
		// Account created but the gateway withheld the session pending email
		// verification. Nothing to store; the person completes verification
		// and signs in with the password they just registered.
		$result['outcome'] = AGEND_APPS_PROVISION_PENDING;
		return $result;
	}

	$result['outcome'] = AGEND_APPS_PROVISION_REGISTERED;
	agend_apps_provision_record_outcome( $user_id, AGEND_APPS_PROVISION_REGISTERED, $parsed['data'], $parsed['session'] );

	return $result;
}

/**
 * Re-entrancy guard for `user_register`.
 *
 * The plugin's own find-or-create (`agend_apps_member_create_user`) inserts a
 * WordPress user for an Agend login or registration that already produced a
 * session, so the `user_register` handler must not register that email a
 * second time.
 */
final class Agend_Apps_Member_Provisioning {

	/**
	 * Depth counter; provisioning is suppressed while it is above zero.
	 *
	 * @var int
	 */
	private static $suppressed = 0;

	/**
	 * Suppresses `user_register` provisioning until `resume()`.
	 */
	public static function suppress(): void {
		++self::$suppressed;
	}

	/**
	 * Lifts one level of suppression.
	 */
	public static function resume(): void {
		self::$suppressed = max( 0, self::$suppressed - 1 );
	}

	/**
	 * Whether `user_register` provisioning is currently suppressed.
	 *
	 * @return bool
	 */
	public static function is_suppressed(): bool {
		return self::$suppressed > 0;
	}
}

/**
 * Registers a dashboard account when WordPress creates a user (wp-admin Users
 * > Add New, WooCommerce, imports, native registration).
 *
 * The plaintext password is available here only when the creating code passed
 * one; otherwise a random password is registered and the person sets their
 * own through the forgot-password flow. Never blocks user creation.
 *
 * @param int   $user_id  Newly created WordPress user id.
 * @param array $userdata Userdata passed to `wp_insert_user()` (WordPress 5.8+).
 */
function agend_apps_member_provision_on_user_register( $user_id, $userdata = array() ): void {
	if ( Agend_Apps_Member_Provisioning::is_suppressed() ) {
		return;
	}

	$user_id  = (int) $user_id;
	$userdata = is_array( $userdata ) ? $userdata : array();
	$user     = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return;
	}

	$email = strtolower( trim( (string) $user->user_email ) );

	if ( '' === $email || ! is_email( $email ) ) {
		return;
	}

	/** This filter is documented in includes/wp-login-bridge.php. */
	if ( ! apply_filters( 'agend_apps_wp_login_bridge_enabled', true, $email ) ) {
		return;
	}

	$password = ( isset( $userdata['user_pass'] ) && is_string( $userdata['user_pass'] ) && '' !== $userdata['user_pass'] )
		? $userdata['user_pass']
		: wp_generate_password( 24, true, true );

	agend_apps_provision_dashboard_account(
		$user_id,
		$email,
		$password,
		isset( $userdata['first_name'] ) ? (string) $userdata['first_name'] : (string) $user->first_name,
		isset( $userdata['last_name'] ) ? (string) $userdata['last_name'] : (string) $user->last_name
	);
}
add_action( 'user_register', 'agend_apps_member_provision_on_user_register', 10, 2 );
