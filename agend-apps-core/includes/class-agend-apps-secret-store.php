<?php
/**
 * Encrypted secret storage for Agend Apps plugins.
 *
 * The canonical implementation of the encrypted-at-rest secret pattern
 * introduced by SPEC-DIR-20260731 v1.2 Decision 2.11: secrets entered in
 * write-only admin fields are encrypted with libsodium
 * (`sodium_crypto_secretbox`, polyfilled by WordPress's bundled sodium_compat
 * where ext-sodium is absent) under a key derived from the site's
 * `AUTH_KEY`/`AUTH_SALT` (`wp_salt( 'auth' )`), and stored in one option per
 * plugin. A database backup alone therefore cannot reveal a secret: it takes
 * the database AND wp-config.php's salts together.
 *
 * Sibling Agend plugins subclass this (overriding `OPTION_SECRETS`) so the
 * crypto exists once; `static::` late static binding routes every read and
 * write to the subclass's own option.
 *
 * Guarantees:
 * - Write-only: `set()` stores; `get()` decrypts for outbound requests only.
 *   Nothing here (or in any admin UI) ever renders a stored secret.
 * - Rotating the WordPress salts invalidates stored secrets. That is
 *   graceful, not fatal: decryption failure reads as "not set" and the
 *   secret is re-entered.
 * - A wp-config.php constant, where a caller names one, takes precedence
 *   over the store via `resolve()`, for installs preferring file secrets.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Apps_Secret_Store' ) ) :
	class Agend_Apps_Secret_Store {

		/**
		 * Option holding this plugin's encrypted secrets map (secret key =>
		 * base64 nonce+ciphertext blob). Subclasses override this so each
		 * plugin's secrets live in their own option.
		 */
		public const OPTION_SECRETS = 'agend_apps_secrets';

		/**
		 * Store key for the Agend gateway API key.
		 */
		public const KEY_API_KEY = 'api_key';

		/**
		 * Resolve a secret for an outbound request: the wp-config.php
		 * constant when defined (highest precedence), otherwise the encrypted
		 * store. Returns '' when neither yields a value.
		 */
		public static function resolve( string $store_key, string $constant_name ): string {
			if ( '' !== $constant_name && defined( $constant_name ) ) {
				return (string) constant( $constant_name );
			}
			return static::get( $store_key );
		}

		/**
		 * Whether a secret is available, and from which source. Feeds admin
		 * status text without touching the secret value itself.
		 *
		 * @return string 'constant' | 'stored' | ''
		 */
		public static function source_of( string $store_key, string $constant_name ): string {
			if ( '' !== $constant_name && defined( $constant_name ) ) {
				return 'constant';
			}
			return '' !== static::get( $store_key ) ? 'stored' : '';
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

			$secrets               = static::all();
			$secrets[ $store_key ] = base64_encode( $nonce . $cipher );

			return update_option( static::OPTION_SECRETS, $secrets, false );
		}

		/**
		 * Decrypt a stored secret. Returns '' when unset, malformed, or
		 * undecryptable (e.g. the WordPress salts rotated since it was
		 * stored) — callers treat all three identically as "not set".
		 */
		public static function get( string $store_key ): string {
			$secrets = static::all();
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
			$secrets = static::all();
			if ( ! array_key_exists( $store_key, $secrets ) ) {
				return;
			}
			unset( $secrets[ $store_key ] );
			update_option( static::OPTION_SECRETS, $secrets, false );
		}

		/**
		 * The stored secrets map for this plugin's option.
		 *
		 * @return array<string, string>
		 */
		protected static function all(): array {
			$secrets = get_option( static::OPTION_SECRETS, array() );
			return is_array( $secrets ) ? $secrets : array();
		}

		/**
		 * The 32-byte encryption key, derived from the site's auth salt. The
		 * salt lives in wp-config.php, so the database alone cannot decrypt.
		 * Shared across subclasses deliberately: the isolation between
		 * plugins is the per-plugin option, not the key.
		 */
		private static function key(): string {
			return hash( 'sha256', wp_salt( 'auth' ), true );
		}
	}
endif;
