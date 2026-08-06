<?php
/**
 * Registry of available Agend Directory Sync data sources.
 *
 * Built-in sources register first; the `agend_directory_sync_sources` filter
 * then lets client code add further sources without forking the plugin
 * (SPEC-DIR-20260731 Decision 2.1). The active source is resolved from the
 * `agend_directory_sync_source` option; an unset or unknown value resolves to
 * `upbeat`, so an existing install never changes which source it syncs from
 * on upgrade.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Source_Registry' ) ) :
	final class Agend_Directory_Sync_Source_Registry {

		/**
		 * Key of the default source, used whenever the configured option is
		 * unset or names an unknown source.
		 */
		public const DEFAULT_SOURCE_KEY = 'upbeat';

		/**
		 * Lazily built registry: source key => source instance. Null until
		 * first accessed, so the `agend_directory_sync_sources` filter is
		 * applied after every plugin has had the chance to add_filter() it.
		 *
		 * @var array<string, Agend_Directory_Sync_Source>|null
		 */
		private static ?array $sources = null;

		/**
		 * Build the registry: register built-in sources, then apply the
		 * public filter. Safe to call more than once; subsequent calls are a
		 * no-op unless `reset()` has been called first (used by tests).
		 */
		public static function register_defaults(): void {
			if ( null !== self::$sources ) {
				return;
			}

			$sources = array();

			$upbeat                        = new Agend_Directory_Sync_Upbeat_Client();
			$sources[ $upbeat->get_key() ] = $upbeat;

			/**
			 * Filter the registered directory sync sources. Add or replace an
			 * entry keyed by its `get_key()` value to register a new source
			 * without forking this plugin.
			 *
			 * @param array<string, Agend_Directory_Sync_Source> $sources Key => source instance.
			 */
			$filtered = apply_filters( 'agend_directory_sync_sources', $sources );

			self::$sources = is_array( $filtered ) ? $filtered : $sources;
		}

		/**
		 * All registered sources, keyed by their `get_key()` value.
		 *
		 * @return array<string, Agend_Directory_Sync_Source>
		 */
		public static function all(): array {
			self::register_defaults();
			return self::$sources ?? array();
		}

		/**
		 * Resolve the active source from the `agend_directory_sync_source`
		 * option. An unset, blank, or unknown value resolves to the default
		 * (`upbeat`) source.
		 */
		public static function active(): Agend_Directory_Sync_Source {
			$sources = self::all();

			$key = trim( (string) get_option( Agend_Directory_Sync::OPTION_SOURCE, '' ) );
			if ( '' === $key || ! isset( $sources[ $key ] ) ) {
				$key = self::DEFAULT_SOURCE_KEY;
			}

			return $sources[ $key ] ?? reset( $sources );
		}

		/**
		 * Reset the registry so it is rebuilt (and the filter re-applied) on
		 * next access. Not used at runtime; exposed for test harnesses.
		 */
		public static function reset(): void {
			self::$sources = null;
		}
	}
endif;
