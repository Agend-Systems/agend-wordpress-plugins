<?php
/**
 * Encrypted secret storage for Agend Directory Sync.
 *
 * A thin subclass of the canonical `Agend_Apps_Secret_Store` (agend-apps-core,
 * which this plugin already requires at bootstrap): the crypto — libsodium
 * secretbox under a key derived from the site's auth salt — lives once in the
 * core plugin, and this subclass only pins the sync plugin's own option so
 * its secrets stay separate from core's. See the parent class for the full
 * guarantees (write-only, encrypted at rest, graceful salt-rotation
 * invalidation, wp-config constant precedence via `resolve()`).
 *
 * SPEC-DIR-20260731 v1.2 Decision 2.11.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Secret_Store' ) && class_exists( 'Agend_Apps_Secret_Store' ) ) :
	final class Agend_Directory_Sync_Secret_Store extends Agend_Apps_Secret_Store {

		/**
		 * This plugin's own encrypted secrets option (kept distinct from
		 * core's `agend_apps_secrets`).
		 */
		public const OPTION_SECRETS = 'agend_directory_sync_secrets';

		/**
		 * Store keys for the connection secrets. The Dataverse client secret
		 * has its own key rather than reusing the Custom HTTP API source's:
		 * the two sources are configured independently and typically point at
		 * different tenants, so one value shared between them would mean
		 * re-credentialing one source silently broke the other.
		 */
		public const KEY_HTTP_TOKEN              = 'http_token';
		public const KEY_OAUTH_CLIENT_SECRET     = 'oauth_client_secret';
		public const KEY_DATAVERSE_CLIENT_SECRET = 'dataverse_client_secret';
	}
endif;
