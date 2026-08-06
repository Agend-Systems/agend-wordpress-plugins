<?php
/**
 * Transform source membership directory contacts into Agend bulk-upsert
 * listings.
 *
 * Pure functions; no I/O. Given an array of source contact rows (as
 * associative arrays) and a resolved field map, returns the Agend listings
 * payload plus a small report of why rows were skipped.
 *
 * The mapping is NOT hard-coded: every Agend target reads its source field
 * from the supplied `$field_map` (see Agend_Directory_Sync_Field_Map). The
 * defaults reproduce the original Upbeat mapping exactly, so an unconfigured
 * install behaves as before, but each target can be repointed to match a
 * different environment, or blanked to omit it entirely.
 *
 * Resolved-map default behaviour:
 * - external_id  <- map[external_id] (Upbeat's stable id by default)
 * - name         <- "first last", falls back to full, then
 *                   "Member <number>", then "Unnamed member"
 * - description  <- map[description] (when present)
 * - email        <- map[email] (when present)
 * - phone        <- map[phone], falls back to map[phone_fallback]
 * - status       <- "approved" when both the eligibility AND opt-in flag
 *                   sources are true (a blank flag source counts as true),
 *                   otherwise "suspended" (the row is preserved but hidden
 *                   from the public directory until the source flags flip)
 * - category_slugs <- [slug(map[category])] when present
 * - tag_slugs    <- [slug(map[tag])] when present
 * - badge_slugs  <- map(slug, map[badges]) when present
 * - hero_image_url <- map[hero_image] (when present, valid URL, <=1000 chars)
 * - custom_fields  <- the resolved custom_fields map, plus designations from
 *                    the badges source
 * - external_metadata <- contributed by the active source (Decision 2.7); when
 *                    no source is supplied (a direct caller), falls back to
 *                    the original Upbeat keys (upbeat_unique_id,
 *                    upbeat_date_modified, synced_at), still resolved through
 *                    the field map and path resolver rather than a hardcoded
 *                    `dateModified` read.
 *
 * Every source-field lookup (`source()`, `raw_value()`, `flag()`, the
 * location slot sub-fields, and the `date_modified` metadata source) resolves
 * through Agend_Directory_Sync_Path_Resolver, so a nested source field such as
 * `contact.email` or `addresses.0.suburb` maps without code
 * (SPEC-DIR-20260731 US-3.1). The transformer stays pure: no I/O, no option
 * reads; the resolver is a pure function over the data already passed in.
 *
 * Residential address fields are intentionally NOT mapped. The directory is
 * professional; residential addresses are sensitive and would need an
 * explicit operator decision before being published.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Listing_Transformer' ) ) :
	final class Agend_Directory_Sync_Listing_Transformer {

		public const SKIP_REASON_MISSING_EXTERNAL_ID = 'missing_external_id';

		/**
		 * Status assigned when the member is both eligible AND has opted in to
		 * the directory. Combined with `auto_publish_approved` on the
		 * bulk-upsert call this makes the listing visible on the public
		 * directory.
		 */
		public const STATUS_VISIBLE = 'approved';

		/**
		 * Status assigned when the member has lost eligibility OR opted out.
		 * The row is preserved (no delete) but hidden from the public directory
		 * because the frontend requires `status = 'approved'`. Flips back to
		 * visible automatically on the next sync if the source flags flip back.
		 */
		public const STATUS_HIDDEN = 'suspended';

		/**
		 * Maximum accepted length for the Agend `phone` field. Both the gateway
		 * Zod schema and the underlying business_listings.phone column are
		 * varchar(50); anything longer is dropped on the way out rather than
		 * truncated, so the directory never shows a half-number.
		 */
		public const MAX_PHONE_LENGTH = 50;

		/**
		 * Maximum accepted length for the Agend `hero_image_url` field. Both
		 * the gateway Zod schema and the underlying
		 * business_listings.hero_image_url column are varchar(1000); anything
		 * longer is dropped rather than truncated, since a truncated URL is
		 * unusable.
		 */
		public const MAX_HERO_IMAGE_URL_LENGTH = 1000;

		/**
		 * Maximum number of identifying examples captured per drop reason. Past
		 * this the counter still increments but no extra example is recorded,
		 * keeping the admin-page transient bounded on large syncs.
		 */
		public const MAX_DROPPED_FIELD_EXAMPLES = 50;

		/**
		 * Filter, dedupe and transform an array of source contacts.
		 *
		 * @param array<int, array<string, mixed>>                                $contacts  Source contact rows.
		 * @param array{core: array<string,string>, custom_fields: array<string,string>}|null $field_map Resolved field map; defaults applied when null.
		 * @param Agend_Directory_Sync_Source|null                                $source    Active source, for its external_metadata contribution
		 *                                                                                    (Decision 2.7). Null falls back to the original Upbeat
		 *                                                                                    keys for direct callers (back-compat).
		 *
		 * @return array{
		 *     listings: array<int, array<string, mixed>>,
		 *     skipped: int,
		 *     skip_reasons: array<string, int>,
		 *     duplicate_external_ids: int,
		 *     dropped_fields: array<string, int>,
		 *     dropped_field_examples: array<string, array<int, array{external_id: string, fullname: string}>>,
		 *     status_counts: array<string, int>
		 * }
		 */
		public static function transform_all( array $contacts, ?array $field_map = null, ?Agend_Directory_Sync_Source $source = null ): array {
			$field_map              = self::normalise_map( $field_map );
			$by_external_id         = array();
			$skipped                = 0;
			$skip_reasons           = array();
			$duplicates             = 0;
			$dropped_fields         = array();
			$dropped_field_examples = array();
			$status_counts          = array();

			foreach ( $contacts as $contact ) {
				if ( ! is_array( $contact ) ) {
					continue;
				}

				$skip_reason = self::should_skip( $contact, $field_map );
				if ( null !== $skip_reason ) {
					$skipped++;
					$skip_reasons[ $skip_reason ] = ( $skip_reasons[ $skip_reason ] ?? 0 ) + 1;
					continue;
				}

				$listing     = self::transform_one( $contact, $field_map, $dropped_fields, $dropped_field_examples, $source );
				$external_id = $listing['external_id'];
				$status      = (string) ( $listing['status'] ?? '' );

				$status_counts[ $status ] = ( $status_counts[ $status ] ?? 0 ) + 1;

				if ( isset( $by_external_id[ $external_id ] ) ) {
					$duplicates++;
				}

				// Last-write-wins on duplicates. The source shouldn't issue
				// duplicates for the same external id but we guard anyway,
				// since the Agend API rejects intra-batch conflicts.
				$by_external_id[ $external_id ] = $listing;
			}

			return array(
				'listings'               => array_values( $by_external_id ),
				'skipped'                => $skipped,
				'skip_reasons'           => $skip_reasons,
				'duplicate_external_ids' => $duplicates,
				'dropped_fields'         => $dropped_fields,
				'dropped_field_examples' => $dropped_field_examples,
				'status_counts'          => $status_counts,
			);
		}

		/**
		 * Fill in any missing map sections from the defaults so callers can
		 * pass a partial map (or none) safely.
		 *
		 * @param array{core?: array<string,string>, custom_fields?: array<string,string>}|null $field_map
		 *
		 * @return array{core: array<string,string>, custom_fields: array<string,string>}
		 */
		private static function normalise_map( ?array $field_map ): array {
			$defaults = class_exists( 'Agend_Directory_Sync_Field_Map' )
				? Agend_Directory_Sync_Field_Map::defaults()
				: array( 'core' => array(), 'custom_fields' => array() );

			if ( null === $field_map ) {
				return $defaults;
			}

			$core          = isset( $field_map['core'] ) && is_array( $field_map['core'] )
				? array_merge( $defaults['core'], $field_map['core'] )
				: $defaults['core'];
			$custom_fields = isset( $field_map['custom_fields'] ) && is_array( $field_map['custom_fields'] )
				? $field_map['custom_fields']
				: $defaults['custom_fields'];
			$locations     = isset( $field_map['locations'] ) && is_array( $field_map['locations'] )
				? $field_map['locations']
				: ( $defaults['locations'] ?? array() );

			return array(
				'core'          => $core,
				'custom_fields' => $custom_fields,
				'locations'     => $locations,
			);
		}

		/**
		 * Read a source field from a contact using the configured source name
		 * (a plain field name or a dot-path, resolved via
		 * Agend_Directory_Sync_Path_Resolver). A blank source name yields ''
		 * so the target is omitted.
		 *
		 * Public so a source's `get_external_metadata()` implementation
		 * (e.g. Agend_Directory_Sync_Upbeat_Client) can resolve a field-map
		 * source the same way the transformer does, without a duplicate
		 * implementation (SPEC-DIR-20260731 Decision 2.3, US-1.1 criterion 8).
		 *
		 * @param array<string, mixed>  $contact
		 * @param array<string, string> $core
		 * @param string                $key
		 */
		public static function resolve_source_field( array $contact, array $core, string $key ): string {
			$field = (string) ( $core[ $key ] ?? '' );
			if ( '' === $field ) {
				return '';
			}
			return self::stringy( Agend_Directory_Sync_Path_Resolver::resolve( $contact, $field ) );
		}

		/**
		 * Alias of resolve_source_field() kept for readability at call sites
		 * within this class.
		 *
		 * @param array<string, mixed>  $contact
		 * @param array<string, string> $core
		 * @param string                $key
		 */
		private static function source( array $contact, array $core, string $key ): string {
			return self::resolve_source_field( $contact, $core, $key );
		}

		/**
		 * Decide whether to skip a contact entirely. Only a missing external id
		 * is a skip condition. Eligibility / opt-in state are status decisions
		 * handled by `resolve_status()` so a member who later regains
		 * eligibility flips back to visible on the next sync without a delete.
		 *
		 * @param array<string, mixed>                                  $contact
		 * @param array{core: array<string,string>, custom_fields: array<string,string>} $field_map
		 */
		private static function should_skip( array $contact, array $field_map ): ?string {
			$external_id = self::source( $contact, $field_map['core'], 'external_id' );
			if ( '' === $external_id ) {
				return self::SKIP_REASON_MISSING_EXTERNAL_ID;
			}

			return null;
		}

		/**
		 * Resolve the Agend listing status from the two visibility flag
		 * sources. Both must be true for the listing to be publicly visible;
		 * otherwise the row is kept as `suspended` (hidden but preserved). A
		 * blank flag source is treated as true, so an environment without an
		 * eligibility / opt-in concept publishes everyone.
		 *
		 * @param array<string, mixed>                                  $contact
		 * @param array{core: array<string,string>, custom_fields: array<string,string>} $field_map
		 */
		private static function resolve_status( array $contact, array $field_map ): string {
			$eligible = self::flag( $contact, $field_map['core'], 'eligible_flag' );
			$opted_in = self::flag( $contact, $field_map['core'], 'opt_in_flag' );

			return ( $eligible && $opted_in ) ? self::STATUS_VISIBLE : self::STATUS_HIDDEN;
		}

		/**
		 * Read a boolean flag source, resolved via the path resolver. A blank
		 * source name counts as true (the environment has no such gate);
		 * otherwise the source value must be boolean true. A path that fails
		 * to resolve behaves identically to a missing flat field (resolves to
		 * null, so the strict `true ===` check is false) — the blank-source
		 * true rule is unaffected (SPEC-DIR-20260731 US-3.1 criterion 4).
		 *
		 * @param array<string, mixed>  $contact
		 * @param array<string, string> $core
		 * @param string                $key
		 */
		private static function flag( array $contact, array $core, string $key ): bool {
			$field = (string) ( $core[ $key ] ?? '' );
			if ( '' === $field ) {
				return true;
			}
			return true === Agend_Directory_Sync_Path_Resolver::resolve( $contact, $field );
		}

		/**
		 * Transform a single source contact into an Agend listing payload.
		 *
		 * @param array<string, mixed>                                              $contact
		 * @param array{core: array<string,string>, custom_fields: array<string,string>} $field_map
		 * @param array<string, int>                                                $dropped_fields         Mutated counter of fields
		 *                                                                                                  dropped because they fail a
		 *                                                                                                  length / format check.
		 * @param array<string, array<int, array{external_id: string, fullname: string}>> $dropped_field_examples Mutated map of identifying
		 *                                                                                                    details for the first N rows
		 *                                                                                                    affected by each drop reason.
		 * @param Agend_Directory_Sync_Source|null                                  $source                 Active source, for its
		 *                                                                                                    external_metadata contribution.
		 *                                                                                                    Null falls back to the original
		 *                                                                                                    Upbeat keys (back-compat).
		 *
		 * @return array<string, mixed>
		 */
		private static function transform_one(
			array $contact,
			array $field_map,
			array &$dropped_fields,
			array &$dropped_field_examples,
			?Agend_Directory_Sync_Source $source = null
		): array {
			$core = $field_map['core'];

			$listing = array(
				'external_id' => self::source( $contact, $core, 'external_id' ),
				'name'        => self::build_name( $contact, $core ),
				'status'      => self::resolve_status( $contact, $field_map ),
			);

			$description = self::source( $contact, $core, 'description' );
			if ( '' !== $description ) {
				$listing['description'] = $description;
			}

			$email = self::source( $contact, $core, 'email' );
			if ( '' !== $email ) {
				$listing['email'] = $email;
			}

			$phone = self::source( $contact, $core, 'phone' );
			if ( '' === $phone ) {
				$phone = self::source( $contact, $core, 'phone_fallback' );
			}
			if ( '' !== $phone ) {
				if ( strlen( $phone ) > self::MAX_PHONE_LENGTH ) {
					self::record_drop( $dropped_fields, $dropped_field_examples, 'phone_too_long', $contact, $core );
				} else {
					$listing['phone'] = $phone;
				}
			}

			$profile_image_url = self::source( $contact, $core, 'hero_image' );
			if ( '' !== $profile_image_url ) {
				if ( strlen( $profile_image_url ) > self::MAX_HERO_IMAGE_URL_LENGTH ) {
					self::record_drop( $dropped_fields, $dropped_field_examples, 'hero_image_url_too_long', $contact, $core );
				} elseif ( false === filter_var( $profile_image_url, FILTER_VALIDATE_URL ) ) {
					self::record_drop( $dropped_fields, $dropped_field_examples, 'hero_image_url_invalid', $contact, $core );
				} else {
					$listing['hero_image_url'] = $profile_image_url;
				}
			}

			$category_slugs = self::collect_slugs( array( self::raw_value( $contact, $core, 'category' ) ) );
			if ( ! empty( $category_slugs ) ) {
				$listing['category_slugs'] = $category_slugs;
			}

			$tag_slugs = self::collect_slugs( array( self::raw_value( $contact, $core, 'tag' ) ) );
			if ( ! empty( $tag_slugs ) ) {
				$listing['tag_slugs'] = $tag_slugs;
			}

			$designations = self::raw_value( $contact, $core, 'badges' );
			if ( is_array( $designations ) ) {
				$badge_slugs = self::collect_slugs( $designations );
				if ( ! empty( $badge_slugs ) ) {
					$listing['badge_slugs'] = $badge_slugs;
				}
			}

			$custom_fields = self::build_custom_fields( $contact, $field_map );
			if ( ! empty( $custom_fields ) ) {
				$listing['custom_fields'] = $custom_fields;
			}

			$locations = self::build_locations( $contact, $field_map );
			if ( ! empty( $locations ) ) {
				$listing['locations'] = $locations;
			}

			// The external_metadata block is contributed by the active source
			// (Decision 2.7), so the transformer stays source-neutral. A
			// direct caller passing no source (back-compat) gets the
			// original Upbeat keys, still resolved through the field map and
			// path resolver rather than a hardcoded `dateModified` read
			// (US-3.1 criterion 5) — identical output to
			// Agend_Directory_Sync_Upbeat_Client::get_external_metadata().
			$listing['external_metadata'] = null !== $source
				? $source->get_external_metadata( $contact, $core )
				: array(
					'upbeat_unique_id'     => self::source( $contact, $core, 'external_id' ),
					'upbeat_date_modified' => self::source( $contact, $core, 'date_modified' ),
					'synced_at'            => gmdate( 'c' ),
				);

			/**
			 * Filter the transformed Agend listing payload for a single source
			 * contact. Use this to extend or override the mapping in client
			 * code without forking this plugin.
			 *
			 * @param array<string, mixed> $listing Transformed Agend listing.
			 * @param array<string, mixed> $contact Original source contact row.
			 */
			return (array) apply_filters( 'agend_directory_sync_listing_payload', $listing, $contact );
		}

		/**
		 * Return a source value verbatim (not stringified), resolved via the
		 * path resolver, so array-shaped sources such as the badges list
		 * survive. A blank source name, or a path that fails to resolve,
		 * yields null.
		 *
		 * @param array<string, mixed>  $contact
		 * @param array<string, string> $core
		 * @param string                $key
		 *
		 * @return mixed
		 */
		private static function raw_value( array $contact, array $core, string $key ) {
			$field = (string) ( $core[ $key ] ?? '' );
			if ( '' === $field ) {
				return null;
			}
			return Agend_Directory_Sync_Path_Resolver::resolve( $contact, $field );
		}

		/**
		 * Build the listing name with sensible fallbacks, all driven by the
		 * configured name sources.
		 *
		 * @param array<string, mixed>  $contact
		 * @param array<string, string> $core
		 */
		private static function build_name( array $contact, array $core ): string {
			$first = self::source( $contact, $core, 'name_first' );
			$last  = self::source( $contact, $core, 'name_last' );

			$name = trim( $first . ' ' . $last );
			if ( '' !== $name ) {
				return $name;
			}

			$fullname = self::source( $contact, $core, 'name_full' );
			if ( '' !== $fullname ) {
				return $fullname;
			}

			$membership_number = self::source( $contact, $core, 'name_fallback_number' );
			if ( '' !== $membership_number ) {
				return sprintf( 'Member %s', $membership_number );
			}

			return 'Unnamed member';
		}

		/**
		 * Build the listing `locations` array from the configured location
		 * slots. Each slot maps address sub-fields to source fields; a slot with
		 * any resolved address data becomes one location. The first non-empty
		 * slot is marked primary. Empty slots are skipped, so an unconfigured
		 * mapping produces no locations.
		 *
		 * @param array<string, mixed>                                                                                        $contact
		 * @param array{core: array<string,string>, custom_fields: array<string,string>, locations: array<int, array<string,string>>} $field_map
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private static function build_locations( array $contact, array $field_map ): array {
			$slots = isset( $field_map['locations'] ) && is_array( $field_map['locations'] )
				? $field_map['locations']
				: array();

			$locations = array();
			foreach ( $slots as $slot ) {
				if ( ! is_array( $slot ) ) {
					continue;
				}

				$location = array();

				foreach ( array( 'address_line_1', 'address_line_2', 'city', 'state', 'postcode', 'country' ) as $key ) {
					$source = (string) ( $slot[ $key ] ?? '' );
					$value  = '' !== $source ? self::stringy( Agend_Directory_Sync_Path_Resolver::resolve( $contact, $source ) ) : '';
					if ( '' !== $value ) {
						$location[ $key ] = $value;
					}
				}

				foreach ( array( 'latitude', 'longitude' ) as $key ) {
					$source = (string) ( $slot[ $key ] ?? '' );
					if ( '' === $source ) {
						continue;
					}
					$raw = Agend_Directory_Sync_Path_Resolver::resolve( $contact, $source );
					if ( is_numeric( $raw ) ) {
						$location[ $key ] = (float) $raw;
					}
				}

				// A slot that resolved no address data contributes nothing.
				if ( empty( $location ) ) {
					continue;
				}

				$label = self::stringy( $slot['label'] ?? '' );
				if ( '' !== $label ) {
					$location['name'] = $label;
				}

				// The first location that carries data is the primary.
				$location['is_primary'] = empty( $locations );

				$locations[] = $location;
			}

			return $locations;
		}

		/**
		 * Build the custom_fields map from the configured custom-field sources.
		 * Drops empty values to avoid storing useless rows on the Agend side.
		 * The badges source is additionally preserved verbatim under
		 * `designations`.
		 *
		 * @param array<string, mixed>                                  $contact
		 * @param array{core: array<string,string>, custom_fields: array<string,string>} $field_map
		 *
		 * @return array<string, mixed>
		 */
		private static function build_custom_fields( array $contact, array $field_map ): array {
			$custom_fields = array();

			foreach ( $field_map['custom_fields'] as $target => $source ) {
				$value = self::stringy( Agend_Directory_Sync_Path_Resolver::resolve( $contact, $source ) );
				if ( '' !== $value ) {
					$custom_fields[ $target ] = $value;
				}
			}

			$designations = self::raw_value( $contact, $field_map['core'], 'badges' );
			if ( is_array( $designations ) && ! empty( $designations ) ) {
				$normalised = array_values(
					array_filter(
						array_map(
							array( __CLASS__, 'stringy' ),
							$designations
						),
						static function ( $value ) {
							return '' !== $value;
						}
					)
				);
				if ( ! empty( $normalised ) ) {
					$custom_fields['designations'] = $normalised;
				}
			}

			return $custom_fields;
		}

		/**
		 * Slugify a list of values into kebab-case lowercase ASCII slugs,
		 * dropping empties and duplicates while preserving the original order.
		 *
		 * @param array<int, mixed> $values
		 *
		 * @return array<int, string>
		 */
		private static function collect_slugs( array $values ): array {
			$slugs = array();
			foreach ( $values as $value ) {
				$slug = self::slugify( self::stringy( $value ) );
				if ( '' === $slug ) {
					continue;
				}
				if ( in_array( $slug, $slugs, true ) ) {
					continue;
				}
				$slugs[] = $slug;
			}
			return $slugs;
		}

		/**
		 * Increment the counter for a drop reason and, if there's room under
		 * MAX_DROPPED_FIELD_EXAMPLES, record an identifying example so the
		 * operator can find the source row.
		 *
		 * @param array<string, int>                                                  $dropped_fields
		 * @param array<string, array<int, array{external_id: string, fullname: string}>> $dropped_field_examples
		 * @param string                                                              $reason
		 * @param array<string, mixed>                                                $contact
		 * @param array<string, string>                                               $core
		 */
		private static function record_drop(
			array &$dropped_fields,
			array &$dropped_field_examples,
			string $reason,
			array $contact,
			array $core
		): void {
			$dropped_fields[ $reason ] = ( $dropped_fields[ $reason ] ?? 0 ) + 1;

			if ( ! isset( $dropped_field_examples[ $reason ] ) ) {
				$dropped_field_examples[ $reason ] = array();
			}

			if ( count( $dropped_field_examples[ $reason ] ) >= self::MAX_DROPPED_FIELD_EXAMPLES ) {
				return;
			}

			$fullname = self::source( $contact, $core, 'name_full' );
			if ( '' === $fullname ) {
				$fullname = trim(
					self::source( $contact, $core, 'name_first' ) . ' ' . self::source( $contact, $core, 'name_last' )
				);
			}

			$dropped_field_examples[ $reason ][] = array(
				'external_id' => self::source( $contact, $core, 'external_id' ),
				'fullname'    => $fullname,
			);
		}

		/**
		 * Convert any scalar to a trimmed string, returning '' for nulls and
		 * non-scalars. Public so the custom_fields array_map can reach it.
		 *
		 * @param mixed $value
		 */
		public static function stringy( $value ): string {
			if ( is_string( $value ) ) {
				return trim( $value );
			}
			if ( is_scalar( $value ) ) {
				return trim( (string) $value );
			}
			return '';
		}

		/**
		 * Kebab-case lowercase ASCII slugifier.
		 *
		 * The Agend API expects "kebab-case, lowercase ASCII, hyphens for
		 * spaces" per the bulk-upsert brief.
		 */
		private static function slugify( string $value ): string {
			$value = strtolower( trim( $value ) );
			if ( '' === $value ) {
				return '';
			}

			// Replace non-ASCII chars first via WP's sanitize_title which
			// handles transliteration in WP environments.
			if ( function_exists( 'sanitize_title' ) ) {
				$value = sanitize_title( $value );
			} else {
				$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
				$value = trim( (string) $value, '-' );
			}

			return $value;
		}
	}
endif;
