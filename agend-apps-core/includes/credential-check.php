<?php
/**
 * Credential check: what a public lookup of a certificate or badge code does,
 * and what it is allowed to say.
 *
 * The Credential check surface posts a code here (through the REST route in
 * `includes/rest/lms-routes.php`) and shows what comes back. Everything the
 * visitor can learn is decided in this file:
 *
 * - **What the code may be.** A pasted link is reduced to its code, then the
 *   code goes through the allow-list in credential-guard.php. A certificate
 *   number is never forwarded, so the form cannot be used to walk the
 *   gateway's sequence.
 * - **In what order a visitor is refused.** Format (free, never counted), the
 *   visitor's limits, the captcha, the site-wide ceiling, and only then the
 *   one gateway call. Every accepted input is exactly one call, so there is no
 *   second lookup whose failure could be mistaken for "not found".
 * - **What counts as found.** Only a successful answer that says `valid` and
 *   names the credential. A transport error, a timeout, a 5xx, a 429 or a
 *   missing key scope is "could not check", never "not found": telling someone
 *   a real credential does not exist is the worst thing this can do.
 * - **What is shown.** Only what the Agend learning record holds and the
 *   association may publish: the course or badge name, the dates, the points,
 *   the certificate number. A certificate has no holder name here, no result
 *   has an issuer, and nothing has an expiry today. If the gateway ever sends
 *   a revoked or expired marker the result fails closed to "no longer valid".
 *
 * Nothing here is HTML: the result is data, drawn by the browser with
 * `textContent`, so a gateway string can never become markup.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The most topics a certificate result lists.
 *
 * @var int
 */
const AGEND_APPS_CREDENTIAL_MAX_TOPICS = 5;

/**
 * The course title the gateway sends for a certificate whose course has been
 * deleted. It is a placeholder, not a name, so it is never shown as one.
 *
 * @var string
 */
const AGEND_APPS_CREDENTIAL_UNKNOWN_COURSE = 'Unknown Course';

/**
 * The code in what a visitor typed or pasted.
 *
 * A pasted link is reduced to its code without being fetched: the
 * `agend_code` or `code` query value when it has one, otherwise the segment
 * after `/verify/` or `/badges/`, otherwise the last path segment. Anything
 * that is not a link is returned as typed, trimmed.
 *
 * The result is not checked: pass it to {@see agend_apps_credential_parse_code()}.
 *
 * @param string $input What the visitor entered.
 * @return string The candidate code, or '' when there is none.
 */
function agend_apps_credentials_extract_code( string $input ): string {
	$input = trim( $input );

	// The longest link worth reading is far shorter than this. A very long
	// input is refused rather than parsed.
	if ( strlen( $input ) > 2000 ) {
		return '';
	}

	$is_link = 1 === preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $input )
		|| false !== stripos( $input, '/verify/' )
		|| false !== stripos( $input, '/badges/' )
		|| false !== stripos( $input, 'agend_code=' );

	if ( ! $is_link ) {
		return $input;
	}

	if ( 1 !== preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $input ) ) {
		$input = 'https://' . ltrim( $input, '/' );
	}

	$parts = parse_url( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Plain string parsing, nothing is fetched.

	if ( ! is_array( $parts ) ) {
		return '';
	}

	if ( ! empty( $parts['query'] ) ) {
		$query = array();
		parse_str( (string) $parts['query'], $query );

		foreach ( array( 'agend_code', 'code' ) as $name ) {
			if ( isset( $query[ $name ] ) && is_string( $query[ $name ] ) && '' !== trim( $query[ $name ] ) ) {
				return trim( $query[ $name ] );
			}
		}
	}

	$segments = array_values( array_filter( explode( '/', (string) ( $parts['path'] ?? '' ) ), 'strlen' ) );

	if ( array() === $segments ) {
		return '';
	}

	$position = null;

	foreach ( $segments as $index => $segment ) {
		if ( in_array( strtolower( $segment ), array( 'verify', 'badges' ), true ) ) {
			$position = $index;
		}
	}

	$pick = null !== $position && isset( $segments[ $position + 1 ] ) ? $segments[ $position + 1 ] : $segments[ count( $segments ) - 1 ];

	return trim( rawurldecode( $pick ) );
}

/**
 * Reads what a visitor entered into a certificate or badge code.
 *
 * @param string $input What the visitor entered: a code or a pasted link.
 * @return array{ok: bool, kind: string, code: string, reason: string} `reason`
 *         is '' when `ok`, else `empty`, `uuid` (an internal badge id, which the
 *         LMS Share button copies and which never resolves), `badges_unsupported`
 *         (a badge token on a site whose key cannot check badges) or `format`.
 */
function agend_apps_credentials_parse_input( string $input ): array {
	$refuse = static function ( string $reason ): array {
		return array(
			'ok'     => false,
			'kind'   => '',
			'code'   => '',
			'reason' => $reason,
		);
	};

	$candidate = agend_apps_credentials_extract_code( $input );

	if ( '' === $candidate ) {
		return $refuse( '' === trim( $input ) ? 'empty' : 'format' );
	}

	if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $candidate ) ) {
		return $refuse( 'uuid' );
	}

	$parsed = agend_apps_credential_parse_code( $candidate );

	if ( null === $parsed ) {
		return $refuse( 'format' );
	}

	if ( 'badge' === $parsed['kind'] && ! agend_apps_credentials_badges_available() ) {
		return $refuse( 'badges_unsupported' );
	}

	return array(
		'ok'     => true,
		'kind'   => $parsed['kind'],
		'code'   => $parsed['code'],
		'reason' => '',
	);
}

/**
 * Whether the site's key can check badges as well as certificates.
 *
 * The surface itself needs only the certificate scope (the surface gate cannot
 * say "either"), so a key without the badge scope is a certificate-only check
 * and a badge code is refused with a clear message instead of a gateway
 * refusal.
 *
 * @return bool
 */
function agend_apps_credentials_badges_available(): bool {
	return ! function_exists( 'agend_apps_records_feature_available' ) || agend_apps_records_feature_available( 'lms_credential_badges' );
}

/**
 * The key that lets one page ask for a badge earner's name.
 *
 * The name is returned only when the page that drew the form was set to show
 * it. The route cannot read that setting, so a page with it on carries this
 * key in its config and a request without it never gets a name. It proves the
 * request came from a page the editor configured, not that the visitor is
 * trusted: the same name is on that page for everyone.
 *
 * @return string
 */
function agend_apps_credentials_holder_key(): string {
	return hash_hmac( 'sha256', 'agend-apps-credential-check-holder', wp_salt( 'auth' ) );
}

/**
 * A short piece of plain text from the gateway: no tags, one line of
 * whitespace, at most $max characters. Anything that is not a string or a
 * number is ''.
 *
 * @param mixed $value Raw value.
 * @param int   $max   Most characters.
 * @return string
 */
function agend_apps_credentials_text( $value, int $max = 200 ): string {
	if ( ! is_scalar( $value ) || is_bool( $value ) ) {
		return '';
	}

	$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );

	if ( function_exists( 'mb_substr' ) ) {
		return mb_substr( $text, 0, $max );
	}

	return substr( $text, 0, $max );
}

/**
 * A date from the gateway as the site shows dates.
 *
 * @param mixed $value An ISO date or timestamp string.
 * @return array{iso: string, label: string}|null Null when there is no usable date.
 */
function agend_apps_credentials_date( $value ): ?array {
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return null;
	}

	$timestamp = strtotime( $value );

	if ( false === $timestamp ) {
		return null;
	}

	$format = (string) get_option( 'date_format', 'j F Y' );

	return array(
		'iso'   => (string) wp_date( 'Y-m-d', $timestamp ),
		'label' => (string) wp_date( '' !== $format ? $format : 'j F Y', $timestamp ),
	);
}

/**
 * Whether the record is still to be treated as valid.
 *
 * Nothing the gateway sends today says otherwise: certificates and badges
 * have no expiry or revoked columns, and `valid` only means the record exists.
 * This is the fail-closed handling for the day it does. A `revoked_at` that is
 * set, an `expires_at` in the past, or a `status` that is not an active one
 * ends the credential. An `expires_at` in the future is shown as "valid
 * until". The three are read from the record and from the envelope, so it does
 * not matter which one the gateway puts them in.
 *
 * @param array $data   The `data` object of the gateway answer.
 * @param array $record The certificate or badge object inside it.
 * @param int   $now    Unix time.
 * @return array{state: string, until: string} `state` is `active`, `valid_until` or `ended`.
 */
function agend_apps_credentials_validity( array $data, array $record, int $now ): array {
	$active = array( '', 'active', 'valid', 'issued', 'verified' );
	$result = array(
		'state' => 'active',
		'until' => '',
	);

	foreach ( array( $record, $data ) as $source ) {
		if ( ! empty( $source['revoked_at'] ) ) {
			return array(
				'state' => 'ended',
				'until' => '',
			);
		}

		if ( isset( $source['status'] ) && is_string( $source['status'] ) && ! in_array( strtolower( trim( $source['status'] ) ), $active, true ) ) {
			return array(
				'state' => 'ended',
				'until' => '',
			);
		}

		if ( isset( $source['expires_at'] ) && is_string( $source['expires_at'] ) && '' !== trim( $source['expires_at'] ) ) {
			$expires = strtotime( $source['expires_at'] );

			if ( false === $expires || $expires <= $now ) {
				// An expiry the plugin cannot read is not a licence to call it valid.
				return array(
					'state' => 'ended',
					'until' => '',
				);
			}

			$date = agend_apps_credentials_date( $source['expires_at'] );

			$result = array(
				'state' => 'valid_until',
				'until' => null !== $date ? $date['label'] : '',
			);
		}
	}

	return $result;
}

/**
 * A colour a page may use, or ''. Only a hex colour: it ends up in a style
 * attribute, so nothing else is passed on.
 *
 * @param mixed $value Raw colour.
 * @return string
 */
function agend_apps_credentials_colour( $value ): string {
	return is_string( $value ) && 1 === preg_match( '/^#[0-9a-fA-F]{3,8}$/D', trim( $value ) ) ? trim( $value ) : '';
}

/**
 * An image address a page may load, or ''. HTTPS only.
 *
 * @param mixed $value Raw URL.
 * @return string
 */
function agend_apps_credentials_image_url( $value ): string {
	if ( ! is_string( $value ) || 1 !== preg_match( '#^https://#i', trim( $value ) ) ) {
		return '';
	}

	return esc_url_raw( trim( $value ) );
}

/**
 * Points as the site writes numbers, or '' when there are none to show.
 *
 * @param mixed $value Raw points.
 * @return string
 */
function agend_apps_credentials_points( $value ): string {
	if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
		return '';
	}

	$points = (float) $value;

	return number_format_i18n( $points, floor( $points ) === $points ? 0 : 1 );
}

/**
 * Turns the gateway's answer for a certificate or badge code into one result.
 *
 * @param string $kind     `certificate` or `badge`.
 * @param mixed  $response What the lookup wrapper returned: the decoded answer or a WP_Error.
 * @param bool   $holder   Whether a badge earner's name may be returned.
 * @param int    $now      Unix time.
 * @return array The result. Always has `status`: `valid`, `ended` (the record
 *         exists but is revoked or expired: details follow, shown as no longer
 *         valid), `not_found`, `busy` (with `retry_after`) or `unavailable`.
 *         A `valid` or `ended` result carries `kind`, `title` and the details
 *         the record holds; an `unavailable` one carries an internal `reason`
 *         that is for the log and the editor, never the visitor.
 */
function agend_apps_credentials_classify( string $kind, $response, bool $holder, int $now ): array {
	if ( is_wp_error( $response ) ) {
		$data   = $response->get_error_data();
		$status = is_array( $data ) && isset( $data['status_code'] ) ? (int) $data['status_code'] : 0;
		$code   = $response->get_error_code();

		// A 429 is the gateway telling this site to slow down. The visitor is
		// asked to wait; the credential has not been checked.
		if ( 429 === $status || 'agend_apps_rate_limited' === $code ) {
			return array(
				'status'      => 'busy',
				'retry_after' => 30,
			);
		}

		return array(
			'status' => 'unavailable',
			'reason' => in_array( $code, array( 'agend_apps_scope_missing', 'agend_apps_app_not_allowed' ), true ) ? 'scope_missing' : 'gateway_error',
		);
	}

	$unavailable = array(
		'status' => 'unavailable',
		'reason' => 'unexpected_answer',
	);

	if ( ! is_array( $response ) || ( isset( $response['success'] ) && false === $response['success'] ) ) {
		return $unavailable;
	}

	$data = $response['data'] ?? null;

	if ( ! is_array( $data ) || ! array_key_exists( 'valid', $data ) ) {
		return $unavailable;
	}

	// The gateway answers an unknown code with 200 and `valid: false`, on
	// purpose. This is the only way a visitor is told a code does not exist, and
	// only a real boolean false is one: a verdict in any other form is not
	// something to tell anyone "no" on.
	if ( false === $data['valid'] ) {
		return array( 'status' => 'not_found' );
	}

	if ( true !== $data['valid'] ) {
		return $unavailable;
	}

	$record = $data['badge' === $kind ? 'badge' : 'certificate'] ?? null;

	if ( ! is_array( $record ) ) {
		return $unavailable;
	}

	$result = 'badge' === $kind
		? agend_apps_credentials_badge_result( $record, $holder )
		: agend_apps_credentials_certificate_result( $record );

	// Found means the record names the credential. A record with no name at all
	// is not something to vouch for.
	if ( null === $result ) {
		return $unavailable;
	}

	$validity = agend_apps_credentials_validity( $data, $record, $now );
	$verified = is_string( $data['verified_at'] ?? null ) ? strtotime( $data['verified_at'] ) : false;

	$result['status']      = 'ended' === $validity['state'] ? 'ended' : 'valid';
	$result['valid_until'] = 'valid_until' === $validity['state'] ? $validity['until'] : '';
	// When the gateway looked it up, in the site's timezone. Its own clock is
	// the honest one; the site's is the fallback.
	$result['checked'] = (string) wp_date(
		( (string) get_option( 'date_format', 'j F Y' ) ) . ', ' . ( (string) get_option( 'time_format', 'g:i a' ) ),
		false !== $verified ? $verified : $now
	);

	return $result;
}

/**
 * A certificate record as a result, or null when it names nothing.
 *
 * @param array $record The gateway's `certificate` object.
 * @return array|null
 */
function agend_apps_credentials_certificate_result( array $record ): ?array {
	$title = agend_apps_credentials_text( $record['course_title'] ?? '' );

	if ( '' === $title ) {
		return null;
	}

	$known  = AGEND_APPS_CREDENTIAL_UNKNOWN_COURSE !== $title;
	$topics = array();

	foreach ( is_array( $record['topics'] ?? null ) ? $record['topics'] : array() as $topic ) {
		$topic = agend_apps_credentials_text( $topic, 60 );

		if ( '' !== $topic && ! in_array( $topic, $topics, true ) ) {
			$topics[] = $topic;
		}
	}

	$issued = agend_apps_credentials_date( is_string( $record['issued_at'] ?? null ) ? $record['issued_at'] : ( $record['completion_date'] ?? null ) );

	return array(
		'kind'       => 'certificate',
		'title'      => $known ? $title : '',
		'known'      => $known,
		'number'     => agend_apps_credentials_text( $record['certificate_number'] ?? '', 60 ),
		'issued'     => null !== $issued ? $issued['label'] : '',
		'issued_iso' => null !== $issued ? $issued['iso'] : '',
		'cpd'        => agend_apps_credentials_points( $record['cpd_points'] ?? null ),
		'instructor' => agend_apps_credentials_text( $record['instructor_name'] ?? '', 120 ),
		'topics'     => array_slice( $topics, 0, AGEND_APPS_CREDENTIAL_MAX_TOPICS ),
	);
}

/**
 * A badge record as a result, or null when it names nothing.
 *
 * The earner's name comes from the learner's sign-in profile and can be a
 * nickname, so it is only returned when the page was set to show it.
 *
 * @param array $record The gateway's `badge` object.
 * @param bool  $holder Whether the earner's name may be returned.
 * @return array|null
 */
function agend_apps_credentials_badge_result( array $record, bool $holder ): ?array {
	$title = agend_apps_credentials_text( $record['badge_name'] ?? '' );

	if ( '' === $title ) {
		return null;
	}

	$course = is_array( $record['course'] ?? null ) ? $record['course'] : array();
	$earned = agend_apps_credentials_date( $record['earned_at'] ?? null );
	$earner = is_array( $record['earner'] ?? null ) ? $record['earner'] : array();

	$result = array(
		'kind'       => 'badge',
		'title'      => $title,
		'known'      => true,
		'issued'     => null !== $earned ? $earned['label'] : '',
		'issued_iso' => null !== $earned ? $earned['iso'] : '',
		'course'     => agend_apps_credentials_text( $course['title'] ?? '' ),
		'cpd'        => agend_apps_credentials_points( $course['cpd_points'] ?? null ),
		'image'      => agend_apps_credentials_image_url( $record['badge_image_url'] ?? '' ),
		'colour'     => agend_apps_credentials_colour( $record['badge_color'] ?? '' ),
	);

	if ( $holder ) {
		$name = agend_apps_credentials_text( $earner['display_name'] ?? '', 120 );

		if ( '' !== $name ) {
			$result['holder'] = $name;
		}
	}

	return $result;
}

/**
 * Looks one accepted code up and classifies the answer.
 *
 * The caller has already parsed the code, counted the visitor and checked the
 * captcha. This makes the one gateway call: the wrappers send it with the site
 * key alone and isolated from the site-wide rate-limit state.
 *
 * @param string $kind   `certificate` or `badge`.
 * @param string $code   The canonical code.
 * @param bool   $holder Whether a badge earner's name may be returned.
 * @param int    $now    Unix time.
 * @return array See {@see agend_apps_credentials_classify()}.
 */
function agend_apps_credentials_lookup( string $kind, string $code, bool $holder, int $now ): array {
	$response = 'badge' === $kind ? agend_apps_lms_verify_badge( $code ) : agend_apps_lms_verify_certificate( $code );

	return agend_apps_credentials_classify( $kind, $response, $holder, $now );
}

/**
 * Whether a visitor may be offered the check at all: a captcha is set up (or
 * the development opt-out is on). Without one the form is hidden and the route
 * refuses, so an unconfigured site fails closed instead of opening a public
 * lookup with only a throttle in front of it.
 *
 * @return bool
 */
function agend_apps_credentials_open(): bool {
	return class_exists( 'Agend_Apps_Captcha' ) && Agend_Apps_Captcha::submissions_open();
}

/**
 * Records why a captcha refused a check, so an administrator can find a
 * configuration cause (a hostname not on the allow-list) that the visitor's
 * message cannot name. Only the reason code is recorded: never the token, the
 * secret or the code the visitor entered.
 *
 * @param string $reason Reason code from {@see Agend_Apps_Captcha::verify()}.
 */
function agend_apps_credentials_log_captcha_refusal( string $reason ): void {
	if ( ! function_exists( 'agend_apps_logger' ) ) {
		return;
	}

	agend_apps_logger()->record(
		array(
			'source'        => 'agend-apps-core',
			'direction'     => 'inbound',
			'method'        => 'POST',
			'url'           => rest_url( 'agend-apps/v1/lms/credentials/verify' ),
			'status'        => 'not_configured' === $reason ? 503 : 400,
			'outcome'       => 'captcha_refused',
			'auth_mode'     => 'none',
			'store_bodies'  => false,
			'error_code'    => '' === $reason ? 'unknown' : $reason,
			'error_message' => 'Captcha refused a credential check: ' . ( '' === $reason ? 'unknown' : $reason ),
			'user_id'       => 0,
		)
	);
}

/**
 * Handles one check from the form, start to finish.
 *
 * The order is deliberate: the format (free, never counted), then the
 * visitor's limits (counted once a code is well formed, even if the captcha
 * then fails, so farming tokens is throttled too), then the captcha (a token
 * is single use, so a mistyped code is refused before one is spent), then the
 * site-wide ceiling (spent only on a real lookup), then the call.
 *
 * @param string $input         What the visitor entered.
 * @param string $captcha_token The captcha token the browser produced, or ''.
 * @param bool   $holder        Whether a badge earner's name may be returned.
 * @param string $ip            The visitor's address from {@see agend_apps_visitor_ip()}, or ''.
 * @param int    $now           Unix time.
 * @return array{http: int, body: array} The HTTP status and the JSON body. The
 *         body always has `status`: `valid`, `ended`, `not_found`, `invalid`,
 *         `captcha_failed`, `busy` or `unavailable`.
 */
function agend_apps_credentials_handle( string $input, string $captcha_token, bool $holder, string $ip, int $now ): array {
	if ( ! agend_apps_credentials_open() ) {
		return array(
			'http' => 503,
			'body' => array(
				'status' => 'unavailable',
			),
		);
	}

	$parsed = agend_apps_credentials_parse_input( $input );

	if ( ! $parsed['ok'] ) {
		return array(
			'http' => 400,
			'body' => array(
				'status' => 'invalid',
				'reason' => $parsed['reason'],
			),
		);
	}

	$wait = agend_apps_credential_throttle_visitor( $ip, $now );

	if ( $wait > 0 ) {
		return array(
			'http' => 429,
			'body' => array(
				'status'      => 'busy',
				'retry_after' => $wait,
			),
		);
	}

	$captcha = Agend_Apps_Captcha::verify( $captcha_token, $ip );

	if ( is_wp_error( $captcha ) ) {
		$data   = $captcha->get_error_data();
		$reason = is_array( $data ) ? (string) ( $data['reason'] ?? '' ) : '';

		agend_apps_credentials_log_captcha_refusal( $reason );

		return 'not_configured' === $reason
			? array(
				'http' => 503,
				'body' => array( 'status' => 'unavailable' ),
			)
			: array(
				'http' => 400,
				'body' => array( 'status' => 'captcha_failed' ),
			);
	}

	$wait = agend_apps_credential_throttle_site( $now );

	if ( $wait > 0 ) {
		return array(
			'http' => 429,
			'body' => array(
				'status'      => 'busy',
				'retry_after' => $wait,
			),
		);
	}

	$result = agend_apps_credentials_lookup( $parsed['kind'], $parsed['code'], $holder, $now );

	switch ( $result['status'] ) {
		case 'valid':
		case 'ended':
		case 'not_found':
			return array(
				'http' => 200,
				'body' => $result,
			);

		case 'busy':
			return array(
				'http' => 429,
				'body' => $result,
			);

		default:
			// The reason is for the log and the editor. A visitor gets one
			// generic message whatever went wrong.
			return array(
				'http' => 503,
				'body' => array( 'status' => 'unavailable' ),
			);
	}
}
