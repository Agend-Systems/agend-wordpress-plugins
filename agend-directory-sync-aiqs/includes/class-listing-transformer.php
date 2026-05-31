<?php
/**
 * Transform Upbeat membership directory contacts into Agend bulk-upsert
 * listings.
 *
 * Pure functions; no I/O. Given an array of Upbeat contact rows (as
 * associative arrays), returns the Agend listings payload plus a small
 * report of why rows were skipped.
 *
 * Mapping defaults:
 * - external_id  <- uniqueid (Upbeat's stable id)
 * - name         <- "firstname lastname", falls back to fullname, then
 *                   "Member <membershipNumber>"
 * - description  <- publishedBio (when present)
 * - email        <- email (when present)
 * - phone        <- businessPhone, falls back to homeMobile
 * - status       <- "approved" when both eligibleToFindAMember AND
 *                   memberDirectoryOptIn are true, otherwise "suspended"
 *                   (the row is preserved but hidden from the public
 *                   directory until the source flags flip back)
 * - category_slugs <- [slug(membershipLevel)] when present
 * - tag_slugs    <- [slug(chapter)] when present
 * - badge_slugs  <- map(slug, designation) when present
 * - hero_image_url <- profileImageUrl (when present, valid URL, <=1000 chars)
 * - custom_fields  <- membership_number, membership_type, membership_level,
 *                    job_title, company_name, chapter, honorifics, title,
 *                    linkedin, designations
 * - external_metadata <- upbeat_unique_id, upbeat_date_modified, synced_at
 *
 * Residential address fields are intentionally NOT mapped. The directory
 * is professional; residential addresses are sensitive and would need an
 * explicit AIQS decision before being published.
 *
 * @package Agend_Directory_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Directory_Sync_Listing_Transformer' ) ) :
	final class Agend_Directory_Sync_Listing_Transformer {

		public const SKIP_REASON_MISSING_UNIQUE_ID = 'missing_uniqueid';

		/**
		 * Status assigned when the member is both eligible AND has opted
		 * in to the directory. Combined with `auto_publish_approved` on
		 * the bulk-upsert call this makes the listing visible on the
		 * public directory.
		 */
		public const STATUS_VISIBLE = 'approved';

		/**
		 * Status assigned when the member has lost eligibility OR opted
		 * out. The row is preserved (no delete) but hidden from the
		 * public directory because the frontend requires
		 * `status = 'approved'`. Flips back to visible automatically on
		 * the next sync if the source flags flip back.
		 */
		public const STATUS_HIDDEN = 'suspended';

		/**
		 * Maximum accepted length for the Agend `phone` field. Both the
		 * gateway Zod schema and the underlying business_listings.phone
		 * column are varchar(50); anything longer is dropped on the way
		 * out rather than truncated, so the directory never shows a
		 * half-number.
		 */
		public const MAX_PHONE_LENGTH = 50;

		/**
		 * Maximum accepted length for the Agend `hero_image_url` field.
		 * Both the gateway Zod schema and the underlying
		 * business_listings.hero_image_url column are varchar(1000);
		 * anything longer is dropped rather than truncated, since a
		 * truncated URL is unusable.
		 */
		public const MAX_HERO_IMAGE_URL_LENGTH = 1000;

		/**
		 * Maximum number of identifying examples captured per drop
		 * reason. Past this the counter still increments but no extra
		 * example is recorded, keeping the admin-page transient bounded
		 * on large syncs.
		 */
		public const MAX_DROPPED_FIELD_EXAMPLES = 50;

		/**
		 * Filter, dedupe and transform an array of Upbeat contacts.
		 *
		 * @param array<int, array<string, mixed>> $contacts Upbeat contact rows.
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
		public static function transform_all( array $contacts ): array {
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

				$skip_reason = self::should_skip( $contact );
				if ( null !== $skip_reason ) {
					$skipped++;
					$skip_reasons[ $skip_reason ] = ( $skip_reasons[ $skip_reason ] ?? 0 ) + 1;
					continue;
				}

				$listing     = self::transform_one( $contact, $dropped_fields, $dropped_field_examples );
				$external_id = $listing['external_id'];
				$status      = (string) ( $listing['status'] ?? '' );

				$status_counts[ $status ] = ( $status_counts[ $status ] ?? 0 ) + 1;

				if ( isset( $by_external_id[ $external_id ] ) ) {
					$duplicates++;
				}

				// Last-write-wins on duplicates. Upbeat shouldn't issue
				// duplicates for the same uniqueid but we guard anyway,
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
		 * Decide whether to skip a contact entirely. Eligibility and
		 * opt-in state are NOT skip conditions any more - those are
		 * status decisions handled by `resolve_status()` so that a member
		 * who later regains eligibility / opts back in flips back to
		 * visible on the next sync without a separate delete.
		 *
		 * @param array<string, mixed> $contact
		 */
		private static function should_skip( array $contact ): ?string {
			$unique_id = self::stringy( $contact['uniqueid'] ?? '' );
			if ( '' === $unique_id ) {
				return self::SKIP_REASON_MISSING_UNIQUE_ID;
			}

			return null;
		}

		/**
		 * Resolve the Agend listing status for a contact based on the
		 * two Upbeat visibility flags. Both must be true for the listing
		 * to be publicly visible; otherwise the row is kept as `suspended`
		 * (hidden but preserved).
		 *
		 * @param array<string, mixed> $contact
		 */
		private static function resolve_status( array $contact ): string {
			$eligible = ( true === ( $contact['eligibleToFindAMember'] ?? false ) );
			$opted_in = ( true === ( $contact['memberDirectoryOptIn'] ?? false ) );

			return ( $eligible && $opted_in ) ? self::STATUS_VISIBLE : self::STATUS_HIDDEN;
		}

		/**
		 * Transform a single Upbeat contact into an Agend listing payload.
		 *
		 * @param array<string, mixed>                                              $contact
		 * @param array<string, int>                                                $dropped_fields         Mutated counter of fields
		 *                                                                                                  dropped because they fail
		 *                                                                                                  a length / format check.
		 * @param array<string, array<int, array{external_id: string, fullname: string}>> $dropped_field_examples Mutated map of identifying
		 *                                                                                                    details for the first N rows
		 *                                                                                                    affected by each drop reason,
		 *                                                                                                    so a tenant admin can locate
		 *                                                                                                    and fix the source record.
		 *
		 * @return array<string, mixed>
		 */
		private static function transform_one(
			array $contact,
			array &$dropped_fields,
			array &$dropped_field_examples
		): array {
			$listing = array(
				'external_id' => self::stringy( $contact['uniqueid'] ),
				'name'        => self::build_name( $contact ),
				'status'      => self::resolve_status( $contact ),
			);

			$description = self::stringy( $contact['publishedBio'] ?? '' );
			if ( '' !== $description ) {
				$listing['description'] = $description;
			}

			$email = self::stringy( $contact['email'] ?? '' );
			if ( '' !== $email ) {
				$listing['email'] = $email;
			}

			$phone = self::stringy( $contact['businessPhone'] ?? '' );
			if ( '' === $phone ) {
				$phone = self::stringy( $contact['homeMobile'] ?? '' );
			}
			if ( '' !== $phone ) {
				if ( strlen( $phone ) > self::MAX_PHONE_LENGTH ) {
					self::record_drop( $dropped_fields, $dropped_field_examples, 'phone_too_long', $contact );
				} else {
					$listing['phone'] = $phone;
				}
			}

			$profile_image_url = self::stringy( $contact['profileImageUrl'] ?? '' );
			if ( '' !== $profile_image_url ) {
				if ( strlen( $profile_image_url ) > self::MAX_HERO_IMAGE_URL_LENGTH ) {
					self::record_drop( $dropped_fields, $dropped_field_examples, 'hero_image_url_too_long', $contact );
				} elseif ( false === filter_var( $profile_image_url, FILTER_VALIDATE_URL ) ) {
					self::record_drop( $dropped_fields, $dropped_field_examples, 'hero_image_url_invalid', $contact );
				} else {
					$listing['hero_image_url'] = $profile_image_url;
				}
			}

			$category_slugs = self::collect_slugs( array( $contact['membershipLevel'] ?? null ) );
			if ( ! empty( $category_slugs ) ) {
				$listing['category_slugs'] = $category_slugs;
			}

			$tag_slugs = self::collect_slugs( array( $contact['chapter'] ?? null ) );
			if ( ! empty( $tag_slugs ) ) {
				$listing['tag_slugs'] = $tag_slugs;
			}

			$designations = $contact['designation'] ?? null;
			if ( is_array( $designations ) ) {
				$badge_slugs = self::collect_slugs( $designations );
				if ( ! empty( $badge_slugs ) ) {
					$listing['badge_slugs'] = $badge_slugs;
				}
			}

			$custom_fields = self::build_custom_fields( $contact );
			if ( ! empty( $custom_fields ) ) {
				$listing['custom_fields'] = $custom_fields;
			}

			$listing['external_metadata'] = array(
				'upbeat_unique_id'     => self::stringy( $contact['uniqueid'] ),
				'upbeat_date_modified' => self::stringy( $contact['dateModified'] ?? '' ),
				'synced_at'            => gmdate( 'c' ),
			);

			/**
			 * Filter the transformed Agend listing payload for a single
			 * Upbeat contact. Use this to extend or override the default
			 * mapping in client code without forking this plugin.
			 *
			 * @param array<string, mixed> $listing Transformed Agend listing.
			 * @param array<string, mixed> $contact Original Upbeat contact row.
			 */
			return (array) apply_filters( 'agend_directory_sync_listing_payload', $listing, $contact );
		}

		/**
		 * Build the listing name with sensible fallbacks.
		 *
		 * @param array<string, mixed> $contact
		 */
		private static function build_name( array $contact ): string {
			$first = self::stringy( $contact['firstname'] ?? '' );
			$last  = self::stringy( $contact['lastname'] ?? '' );

			$name = trim( $first . ' ' . $last );
			if ( '' !== $name ) {
				return $name;
			}

			$fullname = self::stringy( $contact['fullname'] ?? '' );
			if ( '' !== $fullname ) {
				return $fullname;
			}

			$membership_number = self::stringy( $contact['membershipNumber'] ?? '' );
			if ( '' !== $membership_number ) {
				return sprintf( 'Member %s', $membership_number );
			}

			return 'Unnamed member';
		}

		/**
		 * Build the custom_fields map. Drops empty values to avoid storing
		 * useless rows on the Agend side.
		 *
		 * @param array<string, mixed> $contact
		 *
		 * @return array<string, mixed>
		 */
		private static function build_custom_fields( array $contact ): array {
			$candidates = array(
				'membership_number' => self::stringy( $contact['membershipNumber'] ?? '' ),
				'membership_type'   => self::stringy( $contact['membershipType'] ?? '' ),
				'membership_level'  => self::stringy( $contact['membershipLevel'] ?? '' ),
				'job_title'         => self::stringy( $contact['jobTitle'] ?? '' ),
				'company_name'      => self::stringy( $contact['companyName'] ?? '' ),
				'chapter'           => self::stringy( $contact['chapter'] ?? '' ),
				'honorifics'        => self::stringy( $contact['honorifics'] ?? '' ),
				'title'             => self::stringy( $contact['title'] ?? '' ),
				'linkedin'          => self::stringy( $contact['linkedIn'] ?? '' ),
			);

			$custom_fields = array_filter(
				$candidates,
				static function ( $value ) {
					return '' !== $value;
				}
			);

			$designations = $contact['designation'] ?? null;
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
		 * dropping empties and duplicates while preserving the original
		 * order.
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
		 * Increment the counter for a drop reason and, if there's room
		 * under MAX_DROPPED_FIELD_EXAMPLES, record an identifying
		 * example so the operator can find the source row in Upbeat.
		 *
		 * @param array<string, int>                                                  $dropped_fields
		 * @param array<string, array<int, array{external_id: string, fullname: string}>> $dropped_field_examples
		 * @param string                                                              $reason
		 * @param array<string, mixed>                                                $contact
		 */
		private static function record_drop(
			array &$dropped_fields,
			array &$dropped_field_examples,
			string $reason,
			array $contact
		): void {
			$dropped_fields[ $reason ] = ( $dropped_fields[ $reason ] ?? 0 ) + 1;

			if ( ! isset( $dropped_field_examples[ $reason ] ) ) {
				$dropped_field_examples[ $reason ] = array();
			}

			if ( count( $dropped_field_examples[ $reason ] ) >= self::MAX_DROPPED_FIELD_EXAMPLES ) {
				return;
			}

			$dropped_field_examples[ $reason ][] = array(
				'external_id' => self::stringy( $contact['uniqueid'] ?? '' ),
				'fullname'    => self::stringy( $contact['fullname'] ?? '' ),
			);
		}

		/**
		 * Convert any scalar to a trimmed string, returning '' for nulls
		 * and non-scalars. Public so the custom_fields array_map can reach
		 * it.
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
