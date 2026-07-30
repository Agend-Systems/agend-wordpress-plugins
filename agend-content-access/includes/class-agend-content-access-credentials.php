<?php
/**
 * Connector service credential.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and verifies the credential Agend uses to read authored content.
 *
 * This is a SERVICE credential, not a user login. It grants exactly one thing,
 * reading content and policies through the connector routes, and it grants no
 * WordPress capability and no Agend member impersonation (US-3.5 criterion 6).
 *
 * Storage is a hash, never the token. The plaintext is returned once at issue
 * time and cannot be recovered afterwards, so a leaked database gives an
 * attacker nothing to replay.
 */
class Agend_Content_Access_Credentials {

	/**
	 * Option holding the credential record.
	 *
	 * @var string
	 */
	const OPTION = 'agend_content_access_connector_credential';

	/**
	 * Header the connector presents the token in.
	 *
	 * A dedicated header rather than `Authorization`, which Core already uses
	 * for the member bearer on OUTBOUND calls. Sharing one header name across
	 * two different credential types in opposite directions is how the wrong
	 * one ends up being accepted.
	 *
	 * @var string
	 */
	const HEADER = 'X-Agend-Connector-Token';

	/**
	 * Bytes of entropy in a generated token.
	 *
	 * @var int
	 */
	const TOKEN_BYTES = 32;

	/**
	 * Issues a new credential, replacing any existing one.
	 *
	 * Rotation is immediate and total: the stored hash is overwritten, so the
	 * previous token stops verifying on the very next request (US-3.5
	 * criterion 5). There is deliberately no grace window. A leaked connector
	 * token reads every restricted document on the site, so "revoked" has to
	 * mean revoked, and the sync can be re-pointed at a new token in seconds.
	 *
	 * @return string The plaintext token. Shown once; never recoverable.
	 */
	public static function issue(): string {
		$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );

		update_option(
			self::OPTION,
			array(
				'hash'       => self::hash( $token ),
				'created_at' => gmdate( 'c' ),
			),
			false
		);

		return $token;
	}

	/**
	 * Revokes the credential entirely.
	 */
	public static function revoke(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Whether a credential is currently issued.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && ! empty( $stored['hash'] );
	}

	/**
	 * When the current credential was issued.
	 *
	 * @return string ISO 8601 timestamp, or '' when none is issued.
	 */
	public static function created_at(): string {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && isset( $stored['created_at'] )
			? (string) $stored['created_at']
			: '';
	}

	/**
	 * Hashes a token for storage and comparison.
	 *
	 * SHA-256 rather than a password hash, deliberately. The input is 256 bits
	 * of `random_bytes`, so it is not guessable and there is nothing for a slow
	 * KDF to protect against; and this runs on every connector request, where a
	 * deliberately slow hash would be a denial-of-service surface.
	 *
	 * @param string $token Plaintext token.
	 * @return string Hex digest.
	 */
	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Verifies a presented token against the stored hash.
	 *
	 * Constant-time comparison: a timing-variable check on a secret is a real
	 * oracle when the attacker can retry freely, which they can here.
	 *
	 * @param string $token Presented token.
	 * @return bool
	 */
	public static function verify( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) || ! is_string( $stored['hash'] ) ) {
			return false;
		}

		return hash_equals( (string) $stored['hash'], self::hash( $token ) );
	}

	/**
	 * Verifies the credential presented on a REST request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function verify_request( $request ): bool {
		$presented = (string) $request->get_header( self::HEADER );

		return self::verify( trim( $presented ) );
	}
}
