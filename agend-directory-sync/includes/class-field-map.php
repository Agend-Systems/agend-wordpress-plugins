<?php
/**
 * Configurable field mapping for Agend Directory Sync.
 *
 * Owns the mapping from source (Upbeat) fields to Agend listing targets so the
 * plugin is not hard-wired to any one environment's field names. Every target
 * has a sensible default that reproduces the original hard-coded behaviour, so
 * an unconfigured install behaves exactly as before.
 *
 * Two maps are stored under a single option:
 * - `core`: a fixed set of Agend targets, each pointing at one source field.
 *           A blank source means "do not map this target" (the field is
 *           omitted from the payload).
 * - `custom_fields`: an open map of `custom_fields` key => source field, so an
 *           operator can surface any source attribute without forking the
 *           plugin.
 *
 * This class is pure configuration: it reads and writes the option and
 * validates input. The transformer receives a resolved map as a parameter and
 * never reads options itself, so it stays unit-testable.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Field_Map' ) ) :
	final class Agend_Directory_Sync_Field_Map {

		/**
		 * Option key holding the resolved field map array
		 * (`array{core: array<string,string>, custom_fields: array<string,string>}`).
		 */
		public const OPTION_FIELD_MAP = 'agend_directory_sync_field_map';

		/**
		 * The two visibility-flag core targets that can optionally use value
		 * map mode instead of the default truthy read.
		 */
		public const FLAG_KEYS = array( 'eligible_flag', 'opt_in_flag' );

		/**
		 * Flag read mode: the source value is coerced to a boolean with
		 * `truthy()` semantics (today's behaviour, invert applies).
		 */
		public const FLAG_MODE_TRUTHY = 'truthy';

		/**
		 * Flag read mode: the source value is matched against a configured
		 * list of value => outcome rows instead of coerced to a boolean.
		 * Invert does not apply in this mode.
		 */
		public const FLAG_MODE_MAP = 'map';

		/**
		 * Outcome: the listing is sent with status `approved`.
		 */
		public const OUTCOME_PUBLISHED = 'published';

		/**
		 * Outcome: the listing is sent with status `pending` (or, when
		 * neither flag is in map mode, folded into the legacy `suspended`
		 * status for back-compat).
		 */
		public const OUTCOME_DRAFT = 'draft';

		/**
		 * Outcome: the row would not be imported at all. Designed but NOT
		 * offered yet: it needs a gateway option that does not exist yet.
		 * Kept in the ranking below so it slots in later, as the most
		 * restrictive rank, without reshuffling every other outcome.
		 * `sanitize_flags()` rejects it today and rewrites it to
		 * OUTCOME_DRAFT.
		 */
		public const OUTCOME_SKIP = 'skip';

		/**
		 * Outcome precedence, ordered least to most restrictive. When
		 * combining the eligibility and opt-in flags, the most restrictive
		 * matched outcome wins.
		 */
		public const OUTCOME_RANKING = array( self::OUTCOME_PUBLISHED, self::OUTCOME_DRAFT, self::OUTCOME_SKIP );

		/**
		 * Outcomes actually selectable today, in the admin UI and in
		 * `sanitize_flags()`. OUTCOME_SKIP stays out of this list until the
		 * gateway grows a "not imported" option.
		 */
		public const OFFERED_OUTCOMES = array( self::OUTCOME_PUBLISHED, self::OUTCOME_DRAFT );

		/**
		 * Maximum accepted rows in a single flag's value map. Past this,
		 * extra posted rows are discarded rather than stored.
		 */
		public const MAX_FLAG_MAP_ROWS = 50;

		/**
		 * The core Agend targets, each defaulting to an EMPTY source field. The
		 * defaults are intentionally blank: the mapping is configured per client
		 * under Tools > Agend Directory Sync (the previous defaults were
		 * AIQS/Upbeat specific). The keys define the target set; a blank source
		 * means "do not map this target".
		 *
		 * @return array<string, string>
		 */
		public static function default_core_map(): array {
			return array(
				'external_id'          => '',
				'name_first'           => '',
				'name_last'            => '',
				'name_full'            => '',
				'name_fallback_number' => '',
				'description'          => '',
				'email'                => '',
				'phone'                => '',
				'phone_fallback'       => '',
				'category'             => '',
				'tag'                  => '',
				'badges'               => '',
				'hero_image'           => '',
				'eligible_flag'        => '',
				'opt_in_flag'          => '',
				// The one core target with a non-blank default (SPEC-DIR-20260731
				// US-3.1 criterion 5): it replaces the transformer's previous
				// hardcoded `$contact['dateModified']` read, so defaulting it to
				// 'dateModified' keeps every existing Upbeat install's synced
				// metadata byte-identical without any settings migration.
				'date_modified'        => 'dateModified',
			);
		}

		/**
		 * Default `custom_fields` map: Agend custom field key => source field.
		 * Empty by default — configure per client.
		 *
		 * @return array<string, string>
		 */
		public static function default_custom_field_map(): array {
			return array();
		}

		/**
		 * Default `flags` map: per-flag invert setting, read mode, value map
		 * and map default outcome. Most eligibility / opt-in sources are
		 * true-means-visible; a source that is instead true-means-hide (e.g.
		 * an "opted out" or "excluded" flag) sets its invert to true rather
		 * than requiring the client to negate the source data itself. Each
		 * flag defaults to truthy mode with no map rows, so an install saved
		 * before map mode existed resolves exactly as before (back-compat).
		 *
		 * @return array<string, mixed>
		 */
		public static function default_flags(): array {
			$flags = array();
			foreach ( self::FLAG_KEYS as $flag_key ) {
				$flags[ $flag_key . '_invert' ]      = false;
				$flags[ $flag_key . '_mode' ]        = self::FLAG_MODE_TRUTHY;
				$flags[ $flag_key . '_map' ]         = array();
				$flags[ $flag_key . '_map_default' ] = self::OUTCOME_DRAFT;
			}
			return $flags;
		}

		/**
		 * Number of configurable address (location) slots exposed for mapping.
		 * A directory listing supports multiple locations; each configured slot
		 * that has address data becomes one listing location.
		 */
		public const LOCATION_SLOTS = 2;

		/**
		 * Source-mappable sub-fields of a single location. `label` is a static
		 * name for the location (not a source field) and is handled separately.
		 *
		 * @return array<int, string>
		 */
		public static function location_field_keys(): array {
			return array(
				'address_line_1',
				'address_line_2',
				'city',
				'state',
				'postcode',
				'country',
				'latitude',
				'longitude',
			);
		}

		/**
		 * Default (empty) location slots: each a static `label` plus a blank
		 * source field per location_field_keys().
		 *
		 * @return array<int, array<string, string>>
		 */
		public static function default_locations(): array {
			$slot = array( 'label' => '' );
			foreach ( self::location_field_keys() as $key ) {
				$slot[ $key ] = '';
			}

			$locations = array();
			for ( $i = 0; $i < self::LOCATION_SLOTS; $i++ ) {
				$locations[] = $slot;
			}
			return $locations;
		}

		/**
		 * The full default map (core + custom_fields + locations + flags).
		 *
		 * @return array{core: array<string,string>, custom_fields: array<string,string>, locations: array<int, array<string,string>>, flags: array<string,bool>}
		 */
		public static function defaults(): array {
			return array(
				'core'          => self::default_core_map(),
				'custom_fields' => self::default_custom_field_map(),
				'locations'     => self::default_locations(),
				'flags'         => self::default_flags(),
			);
		}

		/**
		 * Ordered metadata describing each core target for the admin form. The
		 * `key` matches a `default_core_map()` key; `label` and `description`
		 * are user-facing.
		 *
		 * @return array<int, array{key: string, label: string, description: string}>
		 */
		public static function core_targets(): array {
			return array(
				array(
					'key'         => 'external_id',
					'label'       => __( 'External ID', 'agend-directory-sync' ),
					'description' => __( 'Stable unique identifier. This is the upsert key — a row with no value here is skipped.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'name_first',
					'label'       => __( 'Name: first', 'agend-directory-sync' ),
					'description' => __( 'First-name source. Combined with the last-name source to build the listing name.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'name_last',
					'label'       => __( 'Name: last', 'agend-directory-sync' ),
					'description' => __( 'Last-name source. Combined with the first-name source to build the listing name.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'name_full',
					'label'       => __( 'Name: full (fallback)', 'agend-directory-sync' ),
					'description' => __( 'Used as the listing name when first and last are both empty.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'name_fallback_number',
					'label'       => __( 'Name: number (fallback)', 'agend-directory-sync' ),
					'description' => __( 'Used to build "Member {number}" when no name fields are present.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'description',
					'label'       => __( 'Description', 'agend-directory-sync' ),
					'description' => __( 'Listing description / bio. Blank to omit.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'email',
					'label'       => __( 'Email', 'agend-directory-sync' ),
					'description' => __( 'Contact email. Blank to omit.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'phone',
					'label'       => __( 'Phone', 'agend-directory-sync' ),
					'description' => __( 'Primary phone source. Blank to omit.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'phone_fallback',
					'label'       => __( 'Phone (fallback)', 'agend-directory-sync' ),
					'description' => __( 'Used when the primary phone source is empty. Blank to disable the fallback.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'category',
					'label'       => __( 'Category', 'agend-directory-sync' ),
					'description' => __( 'Source slugified into the first category slug. Blank to omit.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'tag',
					'label'       => __( 'Tag', 'agend-directory-sync' ),
					'description' => __( 'Source slugified into the first tag slug. Blank to omit.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'badges',
					'label'       => __( 'Badges', 'agend-directory-sync' ),
					'description' => __( 'Source array slugified into badge slugs (also preserved verbatim under custom_fields.designations). Blank to omit.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'hero_image',
					'label'       => __( 'Hero image URL', 'agend-directory-sync' ),
					'description' => __( 'Profile / hero image URL. Dropped if not a valid URL or over 1000 chars. Blank to omit.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'eligible_flag',
					'label'       => __( 'Eligibility flag', 'agend-directory-sync' ),
					'description' => __( 'Source gating public visibility. Truthy/falsey values are accepted (1/0, "true"/"false", "yes"/"no", on/off), not only a strict boolean. Blank means "always eligible" in this environment. Use "Invert this flag" below when the source is true-means-hide rather than true-means-show.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'opt_in_flag',
					'label'       => __( 'Opt-in flag', 'agend-directory-sync' ),
					'description' => __( 'Source gating public visibility. Truthy/falsey values are accepted (1/0, "true"/"false", "yes"/"no", on/off), not only a strict boolean. Blank means "always opted in" in this environment. Use "Invert this flag" below when the source is true-means-hide rather than true-means-show.', 'agend-directory-sync' ),
				),
				array(
					'key'         => 'date_modified',
					'label'       => __( 'Date modified', 'agend-directory-sync' ),
					'description' => __( 'Source last-modified timestamp, recorded in external_metadata. Defaults to "dateModified" (the Upbeat field) so existing installs are unchanged.', 'agend-directory-sync' ),
				),
			);
		}

		/**
		 * Resolve the effective field map: the saved option merged over the
		 * defaults. A core key absent from the saved map falls back to its
		 * default; a key saved as an empty string is respected as an explicit
		 * "do not map" instruction. An install saved before `flags` existed
		 * has no `flags` key at all, and resolves to the non-inverted
		 * defaults rather than an error.
		 *
		 * @return array{core: array<string, string>, custom_fields: array<string, string>, locations: array<int, array<string,string>>, flags: array<string, bool>}
		 */
		public static function resolve(): array {
			$saved = get_option( self::OPTION_FIELD_MAP, null );

			if ( ! is_array( $saved ) ) {
				return self::defaults();
			}

			$core = self::default_core_map();
			if ( isset( $saved['core'] ) && is_array( $saved['core'] ) ) {
				foreach ( $core as $key => $default ) {
					if ( array_key_exists( $key, $saved['core'] ) ) {
						$core[ $key ] = (string) $saved['core'][ $key ];
					}
				}
			}

			$custom_fields = self::default_custom_field_map();
			if ( isset( $saved['custom_fields'] ) && is_array( $saved['custom_fields'] ) ) {
				$custom_fields = array();
				foreach ( $saved['custom_fields'] as $target => $source ) {
					$target = self::sanitize_key_segment( (string) $target );
					$source = trim( (string) $source );
					if ( '' !== $target && '' !== $source ) {
						$custom_fields[ $target ] = $source;
					}
				}
			}

			$locations = self::default_locations();
			if ( isset( $saved['locations'] ) && is_array( $saved['locations'] ) ) {
				foreach ( $locations as $i => $slot ) {
					if ( ! isset( $saved['locations'][ $i ] ) || ! is_array( $saved['locations'][ $i ] ) ) {
						continue;
					}
					foreach ( $slot as $field => $default ) {
						if ( array_key_exists( $field, $saved['locations'][ $i ] ) ) {
							$locations[ $i ][ $field ] = (string) $saved['locations'][ $i ][ $field ];
						}
					}
				}
			}

			// sanitize_flags() already fills in every key from the defaults,
			// coercing each to its proper type, so an install saved before
			// map mode existed (no mode/map/map_default keys at all) resolves
			// to truthy mode with an empty map, identical to today.
			$flags = isset( $saved['flags'] ) && is_array( $saved['flags'] )
				? self::sanitize_flags( $saved['flags'] )
				: self::default_flags();

			return array(
				'core'          => $core,
				'custom_fields' => $custom_fields,
				'locations'     => $locations,
				'flags'         => $flags,
			);
		}

		/**
		 * Persist a posted field map. Unknown core keys are discarded; values
		 * are trimmed. Custom fields are parsed from a textarea of
		 * `target_key = source_field` lines.
		 *
		 * @param array<string, mixed> $raw_core      Posted core map (key => source).
		 * @param mixed                $raw_custom    Posted custom map (textarea string or array).
		 * @param mixed                $raw_locations Posted location slots (array of slot => field => source).
		 * @param mixed                $raw_flags     Posted flag invert checkboxes (key => '1' when checked).
		 *
		 * @return void
		 */
		public static function save( array $raw_core, $raw_custom, $raw_locations = array(), $raw_flags = array() ): void {
			update_option(
				self::OPTION_FIELD_MAP,
				array(
					'core'          => self::sanitize_core( $raw_core ),
					'custom_fields' => self::sanitize_custom_fields( $raw_custom ),
					'locations'     => self::sanitize_locations( $raw_locations ),
					'flags'         => self::sanitize_flags( $raw_flags ),
				)
			);
		}

		/**
		 * Validate posted flag settings against the known flag keys: the
		 * invert checkbox, the read mode, the value map, and the map's
		 * default outcome.
		 *
		 * An HTML checkbox omits its field entirely when unchecked, so
		 * presence (any truthy value, conventionally '1') means true and
		 * absence means false: there is no third state.
		 *
		 * @param mixed $raw Posted flags.
		 *
		 * @return array<string, mixed>
		 */
		public static function sanitize_flags( $raw ): array {
			$raw   = is_array( $raw ) ? $raw : array();
			$flags = array();

			foreach ( self::FLAG_KEYS as $flag_key ) {
				$flags[ $flag_key . '_invert' ]      = ! empty( $raw[ $flag_key . '_invert' ] );
				$flags[ $flag_key . '_mode' ]        = self::sanitize_flag_mode( $raw[ $flag_key . '_mode' ] ?? null );
				$flags[ $flag_key . '_map' ]         = self::sanitize_flag_map( $raw[ $flag_key . '_map' ] ?? null );
				$flags[ $flag_key . '_map_default' ] = self::sanitize_flag_outcome( $raw[ $flag_key . '_map_default' ] ?? null );
			}

			return $flags;
		}

		/**
		 * Validate a posted flag read mode. Anything other than the exact
		 * map-mode string falls back to truthy mode, including a missing key
		 * (an install saved before map mode existed).
		 *
		 * @param mixed $raw
		 */
		private static function sanitize_flag_mode( $raw ): string {
			return self::FLAG_MODE_MAP === $raw ? self::FLAG_MODE_MAP : self::FLAG_MODE_TRUTHY;
		}

		/**
		 * Validate a posted outcome against OFFERED_OUTCOMES. Anything else
		 * (an unknown string, OUTCOME_SKIP, which is reserved but not
		 * offered yet, see the class const docblock, or a missing value)
		 * becomes OUTCOME_DRAFT, the safer (more restrictive) fallback.
		 *
		 * @param mixed $raw
		 */
		private static function sanitize_flag_outcome( $raw ): string {
			return in_array( $raw, self::OFFERED_OUTCOMES, true ) ? (string) $raw : self::OUTCOME_DRAFT;
		}

		/**
		 * Validate a posted flag value map: rows with a blank value are
		 * dropped, a value repeated case-insensitively keeps only the last
		 * row for it (later rows win), the outcome is validated the same way
		 * as the map default, and the result is capped at
		 * MAX_FLAG_MAP_ROWS distinct values.
		 *
		 * @param mixed $raw Posted rows, each `{value, outcome}`.
		 *
		 * @return array<int, array{value: string, outcome: string}>
		 */
		private static function sanitize_flag_map( $raw ): array {
			$raw = is_array( $raw ) ? $raw : array();

			$by_lower_value = array();
			foreach ( $raw as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$value = trim( (string) ( $row['value'] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}

				// Case-insensitive de-dupe on the value: the last posted row
				// for a given value wins, but keeps its original position so
				// row order in the admin UI stays stable.
				$by_lower_value[ strtolower( $value ) ] = array(
					'value'   => $value,
					'outcome' => self::sanitize_flag_outcome( $row['outcome'] ?? null ),
				);
			}

			return array_slice( array_values( $by_lower_value ), 0, self::MAX_FLAG_MAP_ROWS );
		}

		/**
		 * Validate posted location slots against the known slot count and
		 * field keys. `label` is free text; the address sub-fields are source
		 * paths.
		 *
		 * @param mixed $raw Posted locations (array of slot index => field => value).
		 *
		 * @return array<int, array<string, string>>
		 */
		public static function sanitize_locations( $raw ): array {
			$raw       = is_array( $raw ) ? $raw : array();
			$locations = self::default_locations();

			foreach ( $locations as $i => $slot ) {
				$posted = isset( $raw[ $i ] ) && is_array( $raw[ $i ] ) ? $raw[ $i ] : array();

				$locations[ $i ]['label'] = isset( $posted['label'] )
					? sanitize_text_field( (string) $posted['label'] )
					: '';

				foreach ( self::location_field_keys() as $key ) {
					$locations[ $i ][ $key ] = isset( $posted[ $key ] )
						? self::sanitize_source_path( (string) $posted[ $key ] )
						: '';
				}
			}

			return $locations;
		}

		/**
		 * Validate a posted core map against the known target keys.
		 *
		 * @param array<string, mixed> $raw
		 *
		 * @return array<string, string>
		 */
		public static function sanitize_core( array $raw ): array {
			$core = array();
			foreach ( array_keys( self::default_core_map() ) as $key ) {
				$value        = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : '';
				$core[ $key ] = self::sanitize_source_path( $value );
			}
			return $core;
		}

		/**
		 * Parse a `target_key = source_field` textarea (or array) into a
		 * validated custom-field map. Lines without an `=` are dropped, as are
		 * lines with an empty key or source.
		 *
		 * @param mixed $raw Textarea string or associative array.
		 *
		 * @return array<string, string>
		 */
		public static function sanitize_custom_fields( $raw ): array {
			$map = array();

			if ( is_array( $raw ) ) {
				foreach ( $raw as $target => $source ) {
					$target = self::sanitize_key_segment( (string) $target );
					$source = self::sanitize_source_path( (string) $source );
					if ( '' !== $target && '' !== $source ) {
						$map[ $target ] = $source;
					}
				}
				return $map;
			}

			$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
			foreach ( $lines as $line ) {
				if ( false === strpos( $line, '=' ) ) {
					continue;
				}
				list( $target, $source ) = array_map( 'trim', explode( '=', $line, 2 ) );
				$target                   = self::sanitize_key_segment( $target );
				$source                   = self::sanitize_source_path( $source );
				if ( '' !== $target && '' !== $source ) {
					$map[ $target ] = $source;
				}
			}

			return $map;
		}

		/**
		 * Render the custom-field map as a `target_key = source_field` textarea
		 * body, one mapping per line.
		 *
		 * @param array<string, string> $custom_fields
		 */
		public static function custom_fields_to_textarea( array $custom_fields ): string {
			$lines = array();
			foreach ( $custom_fields as $target => $source ) {
				$lines[] = $target . ' = ' . $source;
			}
			return implode( "\n", $lines );
		}

		/**
		 * Sanitize a source-field path. Source fields are simple attribute
		 * names from the upstream payload; allow word characters, dots, and
		 * hyphens so nested dot-paths (e.g. `contact.email`,
		 * `addresses.0.suburb`) are expressible (SPEC-DIR-20260731 US-3.1),
		 * but strip anything exotic. Resolution semantics live in
		 * Agend_Directory_Sync_Path_Resolver: a purely numeric segment
		 * indexes a list, and an exact top-level key match wins before
		 * dot-path traversal so a saved source name that literally contains a
		 * dot keeps resolving as before.
		 *
		 * A value containing `{` is a concatenation template (US-3.2) rather
		 * than a plain path, so it is sanitised on a wider, still
		 * conservative allow-list that keeps braces, spaces, and common
		 * separators a template's literal text needs (e.g. `{a} {b}`,
		 * `{a}/{b}`, `{a}, {b}`) instead of the strict plain-path charset,
		 * which would otherwise strip the template apart.
		 *
		 * `@` is allowed in both charsets: OData-style APIs (e.g. Dynamics)
		 * annotate a field with a literal key such as
		 * `pca_state@OData.Community.Display.V1.FormattedValue`. The path
		 * resolver's exact top-level key match wins before dot-path
		 * traversal, so that whole literal key resolves correctly once it
		 * survives sanitisation intact.
		 */
		private static function sanitize_source_path( string $value ): string {
			$value = trim( $value );
			if ( '' === $value ) {
				return '';
			}

			if ( Agend_Directory_Sync_Path_Resolver::is_template( $value ) ) {
				$value = function_exists( 'sanitize_text_field' )
					? sanitize_text_field( $value )
					: preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
				$value = preg_replace( '/[^A-Za-z0-9_.\-{}\/,()&:\'+@ ]/', '', (string) $value );
				return trim( (string) $value );
			}

			$value = preg_replace( '/[^A-Za-z0-9_.\-@]/', '', $value );
			return (string) $value;
		}

		/**
		 * Sanitize an Agend custom_fields key into lowercase snake_case ASCII.
		 */
		private static function sanitize_key_segment( string $value ): string {
			$value = strtolower( trim( $value ) );
			if ( '' === $value ) {
				return '';
			}
			$value = preg_replace( '/[^a-z0-9_]+/', '_', $value );
			$value = trim( (string) $value, '_' );
			return (string) $value;
		}
	}
endif;
