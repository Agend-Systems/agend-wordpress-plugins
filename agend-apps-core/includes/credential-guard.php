<?php
/**
 * Guard for public certificate and badge lookups.
 *
 * A lookup is a call to the Agend gateway made with the site's API key for a
 * visitor nobody has vetted, so three rules apply to every path that makes one
 * (the public REST proxies, the Credential check widget, and the PHP wrappers
 * themselves):
 *
 * 1. Only a code shaped like a real verification code is ever forwarded. The
 *    gateway also accepts a certificate number (`CERT-2026-000123`) from one
 *    global sequence, which anyone could walk to read every credential on the
 *    platform. Refusing every other shape here means the sequence is never
 *    reachable through this site, whatever the gateway does.
 * 2. A visitor may make only so many lookups in a minute and an hour.
 * 3. The whole site may make only so many lookups in a minute, so a script
 *    spread across many addresses cannot spend the key's shared budget.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shapes a verification code may take.
 *
 * Only certificates are issued by the database, so these are exhaustive for
 * production data: a `VER-` code (80 random bits), a legacy 8 to 12 character
 * certificate code, and a 32 character badge share token. Each entry names
 * the `kind` the gateway route is for, the `pattern` that accepts it and a
 * `normalise` callable that returns it in the case the gateway matches
 * exactly.
 *
 * A site holding another format extends the list with the
 * `agend_apps_credential_code_shapes` filter.
 *
 * @return array<string, array{kind: string, pattern: string, normalise: callable}>
 */
function agend_apps_credential_code_shapes(): array {
	$shapes = array(
		'verification_code' => array(
			'kind'      => 'certificate',
			'pattern'   => '/^VER-[0-9a-f]{20}$/iD',
			'normalise' => 'agend_apps_credential_normalise_verification_code',
		),
		'badge_token'       => array(
			'kind'      => 'badge',
			'pattern'   => '/^[0-9a-f]{32}$/iD',
			'normalise' => 'strtolower',
		),
		'legacy_code'       => array(
			'kind'      => 'certificate',
			'pattern'   => '/^[A-Za-z0-9]{8,12}$/D',
			'normalise' => 'agend_apps_credential_normalise_legacy_code',
		),
	);

	/**
	 * Filters the code shapes a public lookup will forward.
	 *
	 * Each entry needs `kind` ('certificate' or 'badge'), `pattern` (a full
	 * regular expression, anchored) and `normalise` (a callable taking and
	 * returning the code). An entry missing any of them is ignored.
	 *
	 * @param array $shapes Shape id => definition.
	 */
	return (array) apply_filters( 'agend_apps_credential_code_shapes', $shapes );
}

/**
 * `VER-` plus lowercase hex: the prefix is upper case and the rest lower case,
 * which is the one form the gateway stores. Fixes the phone keyboard that
 * capitalises the first letter ("Ver-3fa...").
 *
 * @param string $code A code that matched the verification-code shape.
 * @return string
 */
function agend_apps_credential_normalise_verification_code( string $code ): string {
	return 'VER-' . strtolower( substr( $code, 4 ) );
}

/**
 * A legacy code is upper case hex when it is exactly 8 hex characters, which
 * is how those were issued. Any other legacy-shaped code is sent as typed,
 * because the gateway matches exactly and there is no canonical case to
 * restore.
 *
 * @param string $code A code that matched the legacy shape.
 * @return string
 */
function agend_apps_credential_normalise_legacy_code( string $code ): string {
	return 1 === preg_match( '/^[0-9a-f]{8}$/iD', $code ) ? strtoupper( $code ) : $code;
}

/**
 * Checks a code against the allow-list and returns it in canonical form.
 *
 * Nothing is looked up, counted or called here, so a refused input costs
 * nothing: it is the first step of every public lookup.
 *
 * @param string      $input Code as typed or pasted. Surrounding whitespace is ignored.
 * @param string|null $kind  'certificate' or 'badge' to accept only that kind
 *                           (a route that is for one kind), or null for either.
 * @return array{kind: string, code: string}|null Null when the input is not an
 *         accepted shape, including every hyphenated certificate number.
 */
function agend_apps_credential_parse_code( string $input, ?string $kind = null ): ?array {
	$code = trim( $input );

	// The longest shape is 32 characters. Refusing anything much longer first
	// keeps a megabyte of pasted text out of the pattern matcher.
	if ( '' === $code || strlen( $code ) > 100 ) {
		return null;
	}

	foreach ( agend_apps_credential_code_shapes() as $shape ) {
		if ( ! is_array( $shape )
			|| ! isset( $shape['kind'], $shape['pattern'], $shape['normalise'] )
			|| ! is_callable( $shape['normalise'] )
		) {
			continue;
		}

		if ( null !== $kind && $shape['kind'] !== $kind ) {
			continue;
		}

		if ( 1 !== preg_match( (string) $shape['pattern'], $code ) ) {
			continue;
		}

		return array(
			'kind' => (string) $shape['kind'],
			'code' => (string) call_user_func( $shape['normalise'], $code ),
		);
	}

	return null;
}

/**
 * Request args shared by the two public verification calls.
 *
 * `unattended`: no member token is minted or attached, because a public lookup
 * is the same for everyone and a signed-in member's token has no business on
 * it. `isolated_rate_limit`: the gateway's refusal of a visitor's lookups is
 * not recorded as the site-wide rate-limit state that stops every other
 * Agend call on the site, and the site's own state does not stop a lookup.
 *
 * @return array{unattended: bool, isolated_rate_limit: bool}
 */
function agend_apps_credential_lookup_args(): array {
	return array(
		'unattended'          => true,
		'isolated_rate_limit' => true,
	);
}

/**
 * The error for a code that is not a shape the plugin forwards.
 *
 * Answered locally, so it never reaches the gateway, a throttle count or the
 * API log.
 *
 * @return WP_Error
 */
function agend_apps_credential_invalid_code_error(): WP_Error {
	return new WP_Error(
		'agend_apps_invalid_credential_code',
		__( 'That does not look like a verification code.', 'agend-apps-core' ),
		array( 'status_code' => 400 )
	);
}

/**
 * The lookup limits.
 *
 * Per visitor: 10 a minute and 60 an hour, which a person checking a list of
 * credentials stays under. Per site: 20 gateway lookups a minute in total,
 * which at the gateway's default key limit of 100 a minute leaves most of the
 * budget for the catalogues. Filterable, not an editor setting.
 *
 * @return array{visitor_per_minute: int, visitor_per_hour: int, site_per_minute: int}
 */
function agend_apps_credential_check_limits(): array {
	$defaults = array(
		'visitor_per_minute' => 10,
		'visitor_per_hour'   => 60,
		'site_per_minute'    => 20,
	);

	/**
	 * Filters the credential lookup limits.
	 *
	 * A value that is not a whole number of at least 1 falls back to its
	 * default: there is no way to switch the throttle off.
	 *
	 * @param array $limits `visitor_per_minute`, `visitor_per_hour`, `site_per_minute`.
	 */
	$filtered = (array) apply_filters( 'agend_apps_credential_check_limits', $defaults );
	$limits   = array();

	foreach ( $defaults as $name => $default ) {
		$value          = isset( $filtered[ $name ] ) && is_numeric( $filtered[ $name ] ) ? (int) $filtered[ $name ] : 0;
		$limits[ $name ] = $value >= 1 ? $value : $default;
	}

	return $limits;
}

/**
 * Counts one lookup against the visitor, and says whether it may go ahead.
 *
 * Call it once a code has passed {@see agend_apps_credential_parse_code()}, so
 * a mistyped code is never counted.
 *
 * @param string $ip  Visitor address from {@see agend_apps_visitor_ip()}, or ''.
 * @param int    $now Unix time.
 * @return int 0 when the visitor may go ahead, otherwise the seconds to wait.
 */
function agend_apps_credential_throttle_visitor( string $ip, int $now ): int {
	$limits = agend_apps_credential_check_limits();

	return agend_apps_public_throttle_visitor(
		'credential-check',
		$ip,
		array(
			MINUTE_IN_SECONDS => $limits['visitor_per_minute'],
			HOUR_IN_SECONDS   => $limits['visitor_per_hour'],
		),
		$now
	);
}

/**
 * Counts one gateway lookup against the whole site, and says whether it may
 * go ahead. Call it immediately before the call, after every check that can
 * still refuse the visitor, so only real lookups use the ceiling.
 *
 * @param int $now Unix time.
 * @return int 0 when the lookup may go ahead, otherwise the seconds to wait.
 */
function agend_apps_credential_throttle_site( int $now ): int {
	$limits = agend_apps_credential_check_limits();

	return agend_apps_public_throttle_site(
		'credential-check',
		array( MINUTE_IN_SECONDS => $limits['site_per_minute'] ),
		$now
	);
}

/**
 * Both throttles in order, for a path with nothing between them (the public
 * GET proxies, which have no captcha to check in between).
 *
 * @param string $ip  Visitor address from {@see agend_apps_visitor_ip()}, or ''.
 * @param int    $now Unix time.
 * @return array{reason: string, retry_after: int} `reason` is '' when the
 *         lookup may go ahead, 'visitor' when this visitor is over their
 *         limit, 'site' when the site ceiling is reached.
 */
function agend_apps_credential_throttle( string $ip, int $now ): array {
	$wait = agend_apps_credential_throttle_visitor( $ip, $now );

	if ( $wait > 0 ) {
		return array(
			'reason'      => 'visitor',
			'retry_after' => $wait,
		);
	}

	$wait = agend_apps_credential_throttle_site( $now );

	if ( $wait > 0 ) {
		return array(
			'reason'      => 'site',
			'retry_after' => $wait,
		);
	}

	return array(
		'reason'      => '',
		'retry_after' => 0,
	);
}
