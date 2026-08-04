<?php
/**
 * Entitlement collector.
 *
 * SPEC-AMS-20260804-upbeat-entitlement-mirror US-2.1. Produces the current
 * mirrorable entitlement state for a member: the kiosk's
 * `get_all_member_entitlements()` (which already merges the member -> account
 * -> ultimate-parent inheritance chain and filters to currently-valid grants),
 * filtered to the configured category allow-list, shaped into the slug
 * convention that is the contract with the gateway (Decision 2.7).
 *
 * This class NEVER reimplements validity or inheritance -- that logic stays in
 * the kiosk plugin, which is never modified (Decision 2.5).
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Agend_Entitlement_Collector' ) ) :

	/**
	 * Collects a member's current mirrorable entitlements from the kiosk.
	 */
	class Agend_Entitlement_Collector {

		/**
		 * Returns the member's current mirrorable entitlement set.
		 *
		 * Calls the kiosk's `get_all_member_entitlements()` (AC1), filters to the
		 * configured category allow-list (AC2), and shapes each surviving row into
		 * `{ slug, label }` (AC3). The result is deterministic: de-duplicated by
		 * slug and sorted ascending, so two calls against the same underlying
		 * state always produce byte-identical output (AC4).
		 *
		 * @param string $member_id Kiosk membership number.
		 * @return array<int, array{slug: string, label: string}> Deterministic, deduplicated, sorted entries.
		 *
		 * @throws RuntimeException When the kiosk plugin is unavailable or the
		 *                          underlying API call fails. An empty array is a
		 *                          VALID state (no entitlements) and must never be
		 *                          produced by an error path (AC4).
		 */
		public static function collect( string $member_id ): array {
			if ( ! class_exists( 'Iugo_Membership_Kiosk_API' ) ) {
				throw new RuntimeException( 'The iugo-membership-kiosk plugin is not available.' );
			}

			try {
				$entitlements = Iugo_Membership_Kiosk_API::instance()->get_all_member_entitlements( $member_id );
			} catch ( Throwable $e ) {
				// get_all_member_entitlements() has no try/catch around its own
				// entitlement-fetch loop: a genuine API failure there
				// (get_entitlements_for_id() returning false into array_merge())
				// surfaces as an uncaught Throwable. "Member not found" returns
				// array() cleanly and never reaches this catch -- that is the
				// valid empty state AC4 requires never come from an error path.
				throw new RuntimeException( 'Failed to retrieve entitlements from the membership kiosk: ' . $e->getMessage(), 0, $e );
			}

			if ( ! is_array( $entitlements ) ) {
				throw new RuntimeException( 'Unexpected response retrieving entitlements from the membership kiosk.' );
			}

			$allowed_categories = Agend_Apps_Settings::get_entitlement_mirror_categories();
			$rows                = array();

			foreach ( $entitlements as $entitlement ) {
				if ( ! $entitlement instanceof Iugo_Membership_Kiosk_API_Entitlement ) {
					continue;
				}

				$row = self::to_mirror_entry( $entitlement, $allowed_categories );

				if ( null !== $row ) {
					$rows[] = $row;
				}
			}

			return self::deduplicate_and_sort( $rows );
		}

		/**
		 * Shapes a single kiosk entitlement into a mirror entry, or null when its
		 * category is not in the allow-list or it slugifies to nothing.
		 *
		 * @param Iugo_Membership_Kiosk_API_Entitlement $entitlement        Source entitlement.
		 * @param array<int, string>                    $allowed_categories Configured category allow-list.
		 * @return array{slug: string, label: string}|null
		 */
		public static function to_mirror_entry( Iugo_Membership_Kiosk_API_Entitlement $entitlement, array $allowed_categories ): ?array {
			$category = (string) $entitlement->get_entitlement_category();

			if ( ! self::category_allowed( $category, $allowed_categories ) ) {
				return null;
			}

			$type = (string) $entitlement->get_entitlement_type();

			$category_slug = self::slugify( $category );
			$type_slug     = self::slugify( $type );

			if ( '' === $category_slug || '' === $type_slug ) {
				return null;
			}

			$label = $entitlement->get_entitlement_display_name();

			if ( empty( $label ) ) {
				$label = $type;
			}

			return array(
				'slug'  => $category_slug . '/' . $type_slug,
				'label' => (string) $label,
			);
		}

		/**
		 * Whether a category is in the allow-list, compared case-insensitively
		 * (Upbeat category names are administrator-entered free text).
		 *
		 * @param string             $category           Entitlement category.
		 * @param array<int, string> $allowed_categories Configured category allow-list.
		 * @return bool
		 */
		public static function category_allowed( string $category, array $allowed_categories ): bool {
			foreach ( $allowed_categories as $allowed ) {
				if ( 0 === strcasecmp( trim( $category ), trim( (string) $allowed ) ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * De-duplicates entries by slug (first label wins) and sorts by slug
		 * ascending, so two calls against the same underlying set always produce
		 * byte-identical output (AC4).
		 *
		 * @param array<int, array{slug: string, label: string}> $rows Unsorted, possibly duplicate entries.
		 * @return array<int, array{slug: string, label: string}>
		 */
		public static function deduplicate_and_sort( array $rows ): array {
			$by_slug = array();

			foreach ( $rows as $row ) {
				if ( ! isset( $by_slug[ $row['slug'] ] ) ) {
					$by_slug[ $row['slug'] ] = $row;
				}
			}

			ksort( $by_slug, SORT_STRING );

			return array_values( $by_slug );
		}

		/**
		 * Returns only the slug values from `collect()`, for callers that need
		 * the flag's value list without the labels (the contact PATCH payload).
		 *
		 * @param array<int, array{slug: string, label: string}> $entries Collector output.
		 * @return array<int, string>
		 */
		public static function slugs_only( array $entries ): array {
			return array_values(
				array_map(
					function ( array $entry ) {
						return $entry['slug'];
					},
					$entries
				)
			);
		}

		/**
		 * Kebab-case lowercase ASCII slugifier.
		 *
		 * Byte-identical to agend-directory-sync's
		 * `Agend_Listing_Transformer::slugify()` (Decision 2.7: the slug
		 * convention is a cross-plugin, cross-repo contract). That method is
		 * private, and agend-directory-sync is not a guaranteed-active dependency
		 * of agend-apps-core, so this class carries its own copy of the identical
		 * algorithm rather than reaching into a sibling plugin.
		 *
		 * @param string $value Raw value.
		 * @return string Kebab-case lowercase ASCII slug, or '' when nothing survives.
		 */
		public static function slugify( string $value ): string {
			$value = strtolower( trim( $value ) );

			if ( '' === $value ) {
				return '';
			}

			// Replace non-ASCII chars first via WP's sanitize_title, which
			// handles transliteration in WP environments.
			if ( function_exists( 'sanitize_title' ) ) {
				$value = sanitize_title( $value );
			} else {
				$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
				$value = trim( (string) $value, '-' );
			}

			return (string) $value;
		}
	}

endif;
