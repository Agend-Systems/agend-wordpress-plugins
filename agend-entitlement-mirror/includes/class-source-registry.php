<?php
/**
 * Registry of available Agend Entitlement Mirror data sources.
 *
 * Built-in sources register first; the `agend_entitlement_mirror_sources`
 * filter then lets client code add further sources without forking the
 * plugin (mirrors agend-directory-sync's class-source-registry.php). The
 * active source is resolved from the `agend_entitlement_mirror_data_source`
 * option; an unset or unknown value resolves to `upbeat`, so an existing
 * install never changes source on upgrade.
 *
 * Deliberately a different option to `Agend_Entitlement_Mirror_Settings::
 * get_entitlement_mirror_source_key()` (`agend_entitlement_mirror_source_key`):
 * that value is the label the mirror declares to the Agend gateway as a
 * grant's provenance (`source_key` on the entitlement-types/grants payload),
 * independent of which LOCAL plugin the raw entitlement data is read from. A
 * site could plausibly read from a custom API while still labelling grants
 * `upbeat` for continuity, so the two must never collide.
 *
 * @package Agend_Entitlement_Mirror
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Mirror_Source_Registry' ) ) :
	final class Agend_Entitlement_Mirror_Source_Registry {

		/**
		 * Option key for the active data source (e.g. `upbeat`, `http_api`).
		 */
		public const OPTION_DATA_SOURCE = 'agend_entitlement_mirror_data_source';

		/**
		 * Key of the default source, used whenever the configured option is
		 * unset or names an unknown source.
		 */
		public const DEFAULT_SOURCE_KEY = 'upbeat';

		/**
		 * Lazily built registry: source key => source instance. Null until
		 * first accessed, so the `agend_entitlement_mirror_sources` filter is
		 * applied after every plugin has had the chance to add_filter() it.
		 *
		 * @var array<string, Agend_Entitlement_Mirror_Source>|null
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

			$upbeat                        = new Agend_Entitlement_Mirror_Upbeat_Source();
			$sources[ $upbeat->get_key() ] = $upbeat;

			/**
			 * Filter the registered entitlement mirror sources. Add or replace
			 * an entry keyed by its `get_key()` value to register a new source
			 * without forking this plugin.
			 *
			 * @param array<string, Agend_Entitlement_Mirror_Source> $sources Key => source instance.
			 */
			$filtered = apply_filters( 'agend_entitlement_mirror_sources', $sources );

			self::$sources = is_array( $filtered ) ? $filtered : $sources;
		}

		/**
		 * All registered sources, keyed by their `get_key()` value.
		 *
		 * @return array<string, Agend_Entitlement_Mirror_Source>
		 */
		public static function all(): array {
			self::register_defaults();
			return self::$sources ?? array();
		}

		/**
		 * Resolve the active source from the `agend_entitlement_mirror_data_source`
		 * option. An unset, blank, or unknown value resolves to the default
		 * (`upbeat`) source.
		 */
		public static function active(): Agend_Entitlement_Mirror_Source {
			$sources = self::all();

			$key = trim( (string) get_option( self::OPTION_DATA_SOURCE, '' ) );
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
