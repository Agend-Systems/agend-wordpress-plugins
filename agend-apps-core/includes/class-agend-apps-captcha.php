<?php
/**
 * Captcha settings and server-side verification for visitor-facing forms.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the captcha settings and verifies a visitor's token with the provider.
 *
 * Three providers are supported: Cloudflare Turnstile, Google reCAPTCHA v3
 * (invisible, scored) and Google reCAPTCHA v2 (the "I'm not a robot" checkbox).
 * The site key is public and rendered into the page. The secret key never is:
 * it lives in the encrypted secret store (or the `AGEND_APPS_CAPTCHA_SECRET`
 * constant) and is only read here, server-side, when a token is verified.
 *
 * Verification fails closed. A provider that is selected but has no secret, a
 * provider that cannot be reached and a malformed answer all refuse the
 * submission, because the alternative is a captcha that silently switches
 * itself off.
 */
final class Agend_Apps_Captcha {

	const PROVIDER_NONE      = 'none';
	const PROVIDER_TURNSTILE = 'turnstile';
	const PROVIDER_RECAPTCHA = 'recaptcha';

	/**
	 * Google reCAPTCHA v2 checkbox. `PROVIDER_RECAPTCHA` keeps its stored value
	 * and means v3, so existing sites are unaffected.
	 */
	const PROVIDER_RECAPTCHA_V2 = 'recaptcha_v2';

	const OPTION_PROVIDER  = 'agend_apps_captcha_provider';
	const OPTION_SITE_KEY  = 'agend_apps_captcha_site_key';
	const OPTION_THRESHOLD = 'agend_apps_captcha_score_threshold';
	const OPTION_ACTION    = 'agend_apps_captcha_action';

	/**
	 * Key of the secret in the encrypted secret store.
	 */
	const SECRET_STORE_KEY = 'captcha_secret';

	/**
	 * Constant that overrides the stored secret.
	 */
	const SECRET_CONSTANT = 'AGEND_APPS_CAPTCHA_SECRET';

	/**
	 * Constant that lets a form submit with no captcha. For local development.
	 */
	const ALLOW_NONE_CONSTANT = 'AGEND_APPS_CAPTCHA_ALLOW_NONE';

	const DEFAULT_THRESHOLD = 0.5;
	const DEFAULT_ACTION    = 'agend_enquiry';

	/**
	 * Google's published reCAPTCHA v2 test site key
	 * (https://developers.google.com/recaptcha/docs/faq). With its matching test
	 * secret every check passes, and Google names this placeholder host as the
	 * token's hostname instead of the site's own.
	 */
	const RECAPTCHA_TEST_SITE_KEY = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';
	const RECAPTCHA_TEST_HOSTNAME = 'testkey.google.com';

	const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const RECAPTCHA_VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * Provider ids and their admin labels.
	 *
	 * @return array<string, string>
	 */
	public static function providers(): array {
		return array(
			self::PROVIDER_NONE      => __( 'None', 'agend-apps-core' ),
			self::PROVIDER_TURNSTILE => __( 'Cloudflare Turnstile', 'agend-apps-core' ),
			self::PROVIDER_RECAPTCHA => __( 'Google reCAPTCHA v3 (invisible, score)', 'agend-apps-core' ),
			self::PROVIDER_RECAPTCHA_V2 => __( 'Google reCAPTCHA v2 (checkbox)', 'agend-apps-core' ),
		);
	}

	/**
	 * Normalises a posted or stored provider id.
	 *
	 * @param mixed $value Raw value.
	 * @return string One of the PROVIDER_ constants.
	 */
	public static function normalize_provider( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return array_key_exists( $value, self::providers() ) ? $value : self::PROVIDER_NONE;
	}

	/**
	 * Clamps a posted score threshold into 0 to 1.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function normalize_threshold( $value ): float {
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_THRESHOLD;
		}

		return max( 0.0, min( 1.0, round( (float) $value, 2 ) ) );
	}

	/**
	 * The selected provider.
	 *
	 * @return string One of the PROVIDER_ constants.
	 */
	public static function provider(): string {
		return self::normalize_provider( get_option( self::OPTION_PROVIDER, self::PROVIDER_NONE ) );
	}

	/**
	 * Whether a provider is selected, whether or not it is fully set up.
	 */
	public static function is_enabled(): bool {
		return self::PROVIDER_NONE !== self::provider();
	}

	/**
	 * Whether the selected provider has both its site key and its secret.
	 * A provider missing either is treated as not configured.
	 */
	public static function is_configured(): bool {
		return self::is_enabled() && '' !== self::site_key() && '' !== self::secret();
	}

	/**
	 * Whether submissions are accepted with no captcha at all. Off unless
	 * `AGEND_APPS_CAPTCHA_ALLOW_NONE` is defined true (local development) or
	 * the `agend_apps_captcha_allow_none` filter says so.
	 */
	public static function allow_none(): bool {
		$allowed = defined( self::ALLOW_NONE_CONSTANT ) && true === constant( self::ALLOW_NONE_CONSTANT );

		/**
		 * Filters whether a form may submit with no captcha configured.
		 *
		 * Development only: without a captcha the only abuse control left is the
		 * gateway's rate limiting.
		 *
		 * @param bool $allowed Whether the constant allows it.
		 */
		return (bool) apply_filters( 'agend_apps_captcha_allow_none', $allowed );
	}

	/**
	 * Whether a form may take submissions: a configured captcha, or the
	 * explicit development opt-out. The form is hidden and the route refuses
	 * otherwise, so an unconfigured site fails closed.
	 */
	public static function submissions_open(): bool {
		return self::is_configured() || self::allow_none();
	}

	/**
	 * The public site key. Safe to render into a page.
	 */
	public static function site_key(): string {
		return trim( (string) get_option( self::OPTION_SITE_KEY, '' ) );
	}

	/**
	 * The minimum reCAPTCHA v3 score that passes.
	 */
	public static function score_threshold(): float {
		return self::normalize_threshold( get_option( self::OPTION_THRESHOLD, self::DEFAULT_THRESHOLD ) );
	}

	/**
	 * The reCAPTCHA v3 action the page executes and the server expects back.
	 */
	public static function action(): string {
		$action = trim( (string) get_option( self::OPTION_ACTION, self::DEFAULT_ACTION ) );

		return '' === $action ? self::DEFAULT_ACTION : $action;
	}

	/**
	 * Whether the site is set up with Google's reCAPTCHA v2 test keys, which
	 * pass every check and so give no protection. For development and staging.
	 */
	public static function uses_test_keys(): bool {
		return self::PROVIDER_RECAPTCHA_V2 === self::provider() && self::RECAPTCHA_TEST_SITE_KEY === self::site_key();
	}

	/**
	 * Where the secret comes from: 'constant', 'stored' or ''. Feeds admin
	 * status text without touching the secret itself.
	 */
	public static function secret_source(): string {
		return Agend_Apps_Secret_Store::source_of( self::SECRET_STORE_KEY, self::SECRET_CONSTANT );
	}

	/**
	 * The secret key. Never output it.
	 */
	private static function secret(): string {
		return Agend_Apps_Secret_Store::resolve( self::SECRET_STORE_KEY, self::SECRET_CONSTANT );
	}

	/**
	 * What a page needs to render the provider's widget: the provider, the
	 * public site key and the reCAPTCHA action. Null when no captcha applies.
	 *
	 * A provider missing its site key or secret yields null too, so a page does
	 * not load a script that cannot work.
	 *
	 * @return array{provider: string, siteKey: string, action: string}|null
	 */
	public static function client_config(): ?array {
		if ( ! self::is_configured() ) {
			return null;
		}

		return array(
			'provider' => self::provider(),
			'siteKey'  => self::site_key(),
			'action'   => self::action(),
		);
	}

	/**
	 * Verifies a visitor's token with the selected provider.
	 *
	 * With no provider configured, passes only when {@see allow_none()} says a
	 * form may submit without one; otherwise refuses with `not_configured`.
	 *
	 * @param string $token     The token the browser produced.
	 * @param string $remote_ip The visitor's IP address, or ''.
	 * @return true|WP_Error `agend_apps_captcha_failed` with a `reason` in the
	 *                       error data on any refusal.
	 */
	public static function verify( string $token, string $remote_ip = '' ) {
		$provider = self::provider();
		$secret   = self::secret();

		if ( self::PROVIDER_NONE === $provider || '' === self::site_key() || '' === $secret ) {
			return self::allow_none() ? true : self::failure( 'not_configured' );
		}

		if ( '' === trim( $token ) ) {
			return self::failure( 'missing_token' );
		}

		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);

		if ( '' !== $remote_ip ) {
			$body['remoteip'] = $remote_ip;
		}

		$response = wp_remote_request(
			self::PROVIDER_TURNSTILE === $provider ? self::TURNSTILE_VERIFY_URL : self::RECAPTCHA_VERIFY_URL,
			array(
				'method'  => 'POST',
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::failure( 'unreachable' );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) ) {
			return self::failure( 'bad_response' );
		}

		if ( empty( $decoded['success'] ) ) {
			return self::failure( 'rejected' );
		}

		if ( ! self::hostname_allowed( $decoded ) ) {
			return self::failure( 'hostname_mismatch' );
		}

		if ( self::PROVIDER_RECAPTCHA === $provider ) {
			return self::check_recaptcha_result( $decoded );
		}

		return true;
	}

	/**
	 * Whether the hostname the provider says the token was minted on is this
	 * site's. A token minted for another site with the same key is refused.
	 * An answer that names no hostname is not refused on that ground.
	 *
	 * @param array $decoded The decoded siteverify answer.
	 */
	private static function hostname_allowed( array $decoded ): bool {
		if ( ! isset( $decoded['hostname'] ) || ! is_string( $decoded['hostname'] ) || '' === $decoded['hostname'] ) {
			return true;
		}

		$allowed = array_filter(
			array(
				wp_parse_url( home_url(), PHP_URL_HOST ),
				wp_parse_url( site_url(), PHP_URL_HOST ),
			)
		);

		// Accepting the test host costs nothing: with the test key every
		// check passes anyway. With any other key it stays refused.
		if ( self::uses_test_keys() ) {
			$allowed[] = self::RECAPTCHA_TEST_HOSTNAME;
		}

		/**
		 * Filters the hostnames a captcha token may have been minted on.
		 *
		 * @param string[] $allowed The site's own hosts.
		 */
		$allowed = array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'agend_apps_captcha_allowed_hostnames', array_values( $allowed ) ) ) );

		return in_array( strtolower( $decoded['hostname'] ), $allowed, true );
	}

	/**
	 * Applies the score and action checks to a successful reCAPTCHA v3 answer.
	 *
	 * @param array $decoded The decoded siteverify answer.
	 * @return true|WP_Error
	 */
	private static function check_recaptcha_result( array $decoded ) {
		if ( ! isset( $decoded['score'] ) || ! is_numeric( $decoded['score'] ) ) {
			return self::failure( 'bad_response' );
		}

		if ( (float) $decoded['score'] < self::score_threshold() ) {
			return self::failure( 'low_score' );
		}

		if ( ! isset( $decoded['action'] ) || self::action() !== (string) $decoded['action'] ) {
			return self::failure( 'action_mismatch' );
		}

		return true;
	}

	/**
	 * A refusal. The reason is for logs and tests; the visitor is never shown it.
	 *
	 * @param string $reason Short reason code.
	 * @return WP_Error
	 */
	private static function failure( string $reason ): WP_Error {
		return new WP_Error(
			'agend_apps_captcha_failed',
			__( 'We could not confirm you are not a robot. Please try again.', 'agend-apps-core' ),
			array( 'reason' => $reason )
		);
	}
}
