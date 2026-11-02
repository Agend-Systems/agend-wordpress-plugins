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
 * Two providers are supported: Cloudflare Turnstile and Google reCAPTCHA v3.
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

	const DEFAULT_THRESHOLD = 0.5;
	const DEFAULT_ACTION    = 'agend_enquiry';

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
			self::PROVIDER_RECAPTCHA => __( 'Google reCAPTCHA v3', 'agend-apps-core' ),
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
	 * Whether a captcha is required for submissions.
	 */
	public static function is_enabled(): bool {
		return self::PROVIDER_NONE !== self::provider();
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
	 * A selected provider with no site key yields null as well, so a page does
	 * not load a script that cannot work. Submissions are still refused by
	 * {@see verify()} in that state.
	 *
	 * @return array{provider: string, siteKey: string, action: string}|null
	 */
	public static function client_config(): ?array {
		if ( ! self::is_enabled() || '' === self::site_key() ) {
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
	 * Returns true straight away when no provider is selected.
	 *
	 * @param string $token     The token the browser produced.
	 * @param string $remote_ip The visitor's IP address, or ''.
	 * @return true|WP_Error `agend_apps_captcha_failed` with a `reason` in the
	 *                       error data on any refusal.
	 */
	public static function verify( string $token, string $remote_ip = '' ) {
		$provider = self::provider();

		if ( self::PROVIDER_NONE === $provider ) {
			return true;
		}

		$secret = self::secret();

		if ( '' === $secret ) {
			return self::failure( 'not_configured' );
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

		if ( self::PROVIDER_RECAPTCHA === $provider ) {
			return self::check_recaptcha_result( $decoded );
		}

		return true;
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
