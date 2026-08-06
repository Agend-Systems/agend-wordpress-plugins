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
 * @package Agend_Entitlement_Mirror
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
		 * Platform-owned gate keys the `gate_key()` conversion must never emit.
		 *
		 * Mirrors the code-owned `MEMBER_GATES` registry in
		 * `packages/@agend/member-gates` on the Agend platform monorepo. The
		 * platform's entitlement-types endpoint refuses any of these server-side
		 * with a 400 (SPEC-CRM-20260805-member-entitlement-grants US-5.1 AC9), so
		 * `gate_key()` must reject them here rather than let a whole sync batch
		 * fail on one collision. Kept as an explicit list, not a call into the
		 * platform, because this plugin has no dependency on that package; the
		 * test suite asserts against the full list so registry growth on the
		 * platform side is caught here by updating both together.
		 *
		 * @var array<int, string>
		 */
		const RESERVED_PLATFORM_GATE_KEYS = array(
			'api.access',
			'community.forum',
			'content.sponsored',
			'directory.access',
			'events.discount',
			'jobs.board',
			'lms.certifications',
			'lms.courses',
			'mentorship.program',
			'resources.library',
		);

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
			/**
			 * Short-circuit the kiosk read for one member (SPEC-AMS-20260804
			 * US-3.1's stub seam: no live Upbeat credentials in any test path).
			 * Return an array of raw kiosk-shaped entitlement rows to use it,
			 * or null (the default) to read the kiosk normally. The category
			 * filter, slugging, dedupe, and sort below still apply, so a stub
			 * exercises everything except the HTTP call itself.
			 *
			 * @param array<int, mixed>|null $entitlements Raw rows, or null.
			 * @param string                 $member_id    Kiosk membership number.
			 */
			$stubbed = apply_filters(
				'agend_entitlement_mirror_raw_entitlements',
				null,
				$member_id
			);

			if ( null !== $stubbed ) {
				if ( ! is_array( $stubbed ) ) {
					throw new RuntimeException( 'Stubbed entitlement rows must be an array.' );
				}
				$entitlements = $stubbed;
			} else {
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
			}

			$allowed_categories = Agend_Entitlement_Mirror_Settings::get_entitlement_mirror_categories();
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
		 * of agend-entitlement-mirror, so this class carries its own copy of the
		 * identical algorithm rather than reaching into a sibling plugin.
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

		/**
		 * Underscore-segment slugifier for the platform `gate_key()` convention.
		 *
		 * Same transliteration path as {@see slugify()} (WP's `sanitize_title()`
		 * when available, otherwise the ASCII fallback), but joins words with
		 * underscores rather than hyphens, since `gate_key()` reserves the dot for
		 * the category/type separator.
		 *
		 * @param string $value Raw value.
		 * @return string Underscore-separated lowercase ASCII segment, or '' when nothing survives.
		 */
		public static function slugify_segment( string $value ): string {
			$value = strtolower( trim( $value ) );

			if ( '' === $value ) {
				return '';
			}

			if ( function_exists( 'sanitize_title' ) ) {
				$value = sanitize_title( $value );
			} else {
				$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
				$value = trim( (string) $value, '-' );
			}

			$value = str_replace( '-', '_', (string) $value );
			$value = preg_replace( '/_+/', '_', $value );
			$value = trim( (string) $value, '_' );

			return (string) $value;
		}

		/**
		 * Converts an Upbeat category/type pair into a platform `gate_key`.
		 *
		 * The result is the entitlement's identity on the platform side across
		 * every sync, so the SAME (category, type) input must always produce the
		 * SAME output (SPEC-CRM-20260805-member-entitlement-grants US-5.1 AC1).
		 * Never emits a key from {@see RESERVED_PLATFORM_GATE_KEYS} (AC9); the
		 * caller is expected to log and skip when this returns ''.
		 *
		 * @param string $category Upbeat entitlement category.
		 * @param string $type     Upbeat entitlement type.
		 * @return string Dot-separated `gate_key`, or '' when no valid key can be derived.
		 */
		public static function gate_key( string $category, string $type ): string {
			$category_segment = self::slugify_segment( $category );
			$type_segment      = self::slugify_segment( $type );

			if ( '' === $category_segment || '' === $type_segment ) {
				return '';
			}

			$key = $category_segment . '.' . $type_segment;

			// The database CHECK requires the key to start with a lowercase
			// letter. A digit-led category (e.g. "2026 Life Member") would
			// otherwise produce an invalid key, so prefix deterministically
			// rather than drop the entitlement.
			if ( ! preg_match( '/^[a-z]/', $key ) ) {
				$key = 'k' . $key;
			}

			if ( strlen( $key ) > 64 ) {
				$key = substr( $key, 0, 64 );
				$key = rtrim( $key, '_.' );
			}

			if ( ! preg_match( '/^[a-z][a-z0-9_.]{1,62}[a-z0-9]$/', $key ) ) {
				return '';
			}

			// The platform refuses these server-side (AC9): emitting one here
			// would fail the whole sync batch rather than just this entitlement.
			if ( in_array( $key, self::RESERVED_PLATFORM_GATE_KEYS, true ) ) {
				return '';
			}

			return $key;
		}
	}

endif;
