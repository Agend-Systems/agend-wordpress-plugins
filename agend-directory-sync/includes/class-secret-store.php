<?php
/**
 * Encrypted secret storage for Agend Directory Sync.
 *
 * Lets an administrator enter connection secrets (the static API token, the
 * OAuth client secret) in the settings UI without persisting them in
 * plaintext (SPEC-DIR-20260731 v1.2 Decision 2.11). Secrets are encrypted
 * with libsodium (`sodium_crypto_secretbox`, polyfilled by WordPress's
 * bundled sodium_compat where ext-sodium is absent) under a key derived from
 * the site's `AUTH_KEY`/`AUTH_SALT` (`wp_salt( 'auth' )`), and stored in one
 * option. A database backup alone therefore cannot reveal a secret: it takes
 * the database AND wp-config.php's salts together.
 *
 * Properties this class guarantees:
 * - Write-only: `set()` stores; `get()` decrypts for outbound requests only.
 *   Nothing here (or in the admin UI) ever renders a stored secret.
 * - Rotating the WordPress salts invalidates stored secrets. That is
 *   graceful, not fatal: decryption failure reads as "not set", the source
 *   reports itself unavailable, and the admin re-enters the secret.
 * - The wp-config.php constants (`AGEND_DIRECTORY_SYNC_HTTP_TOKEN`,
 *   `AGEND_DIRECTORY_SYNC_OAUTH_CLIENT_SECRET`) remain supported and take
 *   precedence over the store via `resolve()`, for installs that prefer
 *   file-based secrets.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Secret_Store' ) ) :
	final class Agend_Directory_Sync_Secret_Store {

		/**
		 * Option holding the encrypted secrets map (secret key => base64
		 * nonce+ciphertext blob).
		 */
		public const OPTION_SECRETS = 'agend_directory_sync_secrets';

		/**
		 * Store keys for the two connection secrets.
		 */
		public const KEY_HTTP_TOKEN         = 'http_token';
		public const KEY_OAUTH_CLIENT_SECRET = 'oauth_client_secret';

		/**
		 * Resolve a secret for an outbound request: the wp-config.php
		 * constant when defined (highest precedence), otherwise the encrypted
		 * store. Returns '' when neither yields a value.
		 */
		public static function resolve( string $store_key, string $constant_name ): string {
			if ( defined( $constant_name ) ) {
				return (string) constant( $constant_name );
			}
			return self::get( $store_key );
		}

		/**
		 * Whether a secret is available from either source, and from which.
		 * Feeds the admin status text and `is_available()` reasons without
		 * touching the secret value itself.
		 *
		 * @return string 'constant' | 'stored' | ''
		 */
		public static function source_of( string $store_key, string $constant_name ): string {
			if ( defined( $constant_name ) ) {
				return 'constant';
			}
			return '' !== self::get( $store_key ) ? 'stored' : '';
		}

		/**
		 * Encrypt and store a secret. An empty plaintext is rejected (use
		 * `delete()` to clear).
		 */
		public static function set( string $store_key, string $plaintext ): bool {
			if ( '' === $plaintext ) {
				return false;
			}

			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

			$secrets               = self::all();
			$secrets[ $store_key ] = base64_encode( $nonce . $cipher );

			return update_option( self::OPTION_SECRETS, $secrets, false );
		}

		/**
		 * Decrypt a stored secret. Returns '' when unset, malformed, or
		 * undecryptable (e.g. the WordPress salts rotated since it was
		 * stored) — callers treat all three identically as "not set".
		 */
		public static function get( string $store_key ): string {
			$secrets = self::all();
			$blob    = isset( $secrets[ $store_key ] ) ? base64_decode( (string) $secrets[ $store_key ], true ) : false;

			if ( false === $blob || strlen( $blob ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}

			$nonce  = substr( $blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

			return false === $plaintext ? '' : $plaintext;
		}

		/**
		 * Remove a stored secret.
		 */
		public static function delete( string $store_key ): void {
			$secrets = self::all();
			if ( ! array_key_exists( $store_key, $secrets ) ) {
				return;
			}
			unset( $secrets[ $store_key ] );
			update_option( self::OPTION_SECRETS, $secrets, false );
		}

		/**
		 * The stored secrets map.
		 *
		 * @return array<string, string>
		 */
		private static function all(): array {
			$secrets = get_option( self::OPTION_SECRETS, array() );
			return is_array( $secrets ) ? $secrets : array();
		}

		/**
		 * The 32-byte encryption key, derived from the site's auth salt. The
		 * salt lives in wp-config.php, so the database alone cannot decrypt.
		 */
		private static function key(): string {
			return hash( 'sha256', wp_salt( 'auth' ), true );
		}
	}
endif;
