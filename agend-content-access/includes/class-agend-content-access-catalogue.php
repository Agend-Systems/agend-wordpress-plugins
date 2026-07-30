<?php
/**
 * Membership plan catalogue for the policy editor.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies the editor's plan picker, server-side and reduced.
 *
 * Three properties this class exists to guarantee (SPEC-CMS-20260727 US-3.3):
 *
 * 1. The tenant API key never reaches the browser. The catalogue is fetched on
 *    the WordPress server through Core's wrapper and only the reduced result is
 *    handed to the editor.
 * 2. The editor receives no pricing, seat, member-count or benefit data.
 *    `GET /v1/crm/tiers` returns all of it; the reduction happens here, in PHP,
 *    rather than by adding a gateway endpoint (Decision 2.13).
 * 3. An Agend outage never widens a policy. A failed refresh keeps the editor
 *    working with the last known catalogue, and where there is no catalogue at
 *    all it blocks NEW plan selections rather than presenting an empty picker
 *    that an editor could read as "no plans exist".
 */
class Agend_Content_Access_Catalogue {

	/**
	 * Transient holding the reduced catalogue.
	 *
	 * Separate from Core's own `crm_tiers` cache: that one holds the full
	 * upstream payload, and this holds only what the editor may see. Keeping
	 * them apart means the reduction cannot be skipped by a cache hit.
	 *
	 * @var string
	 */
	const TRANSIENT = 'agend_content_access_plan_catalogue';

	/**
	 * Core cache key whose configured TTL this reuses, so an administrator
	 * tuning tier caching gets the expected behaviour here too.
	 *
	 * @var string
	 */
	const TTL_KEY = 'crm_tiers';

	/**
	 * Returns the plan catalogue for the editor.
	 *
	 * @param bool $force_refresh Optional. Bypass the local transient. Default false.
	 * @return array{
	 *     plans: array<int, array{id: string, name: string, slug: string, type: string, active: bool}>,
	 *     stale: bool,
	 *     error: string
	 * } Plans, whether they came from a stale cache after a failed refresh, and
	 *   a human-readable error when one occurred.
	 */
	public static function get( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return array(
					'plans' => $cached,
					'stale' => false,
					'error' => '',
				);
			}
		}

		$response = agend_apps_crm_get_tiers();

		if ( is_wp_error( $response ) ) {
			// Never clobber a known-good catalogue with a failure. The editor
			// keeps working; it is simply told the list may be out of date.
			$fallback = get_transient( self::TRANSIENT );

			return array(
				'plans' => is_array( $fallback ) ? $fallback : array(),
				'stale' => true,
				'error' => $response->get_error_message(),
			);
		}

		$plans = self::reduce( $response );

		set_transient(
			self::TRANSIENT,
			$plans,
			Agend_Apps_Settings::get_cache_ttl( self::TTL_KEY )
		);

		return array(
			'plans' => $plans,
			'stale' => false,
			'error' => '',
		);
	}

	/**
	 * Reduces the gateway tier payload to the editor contract.
	 *
	 * Field-by-field allow list, not a blocklist. `GET /v1/crm/tiers` returns
	 * price_cents, seat_brackets, formatted_price, member_count, benefits and
	 * app_access among others, and none of that belongs in an editor picker.
	 * Copying named fields means a new upstream field is excluded by default
	 * rather than leaking until somebody notices.
	 *
	 * @param array $response Decoded `GET /v1/crm/tiers` response.
	 * @return array<int, array{id: string, name: string, slug: string, type: string, active: bool}>
	 */
	public static function reduce( array $response ): array {
		$rows = isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: $response;

		$plans = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}

			$plans[] = array(
				'id'     => (string) $row['id'],
				'name'   => isset( $row['name'] ) ? (string) $row['name'] : '',
				'slug'   => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'type'   => isset( $row['tier_type'] ) ? (string) $row['tier_type'] : '',
				// Absent is treated as INACTIVE. A plan we cannot confirm is
				// active is not offered for new selections.
				'active' => ! empty( $row['is_active'] ),
			);
		}

		return $plans;
	}

	/**
	 * Plans offerable for a NEW selection: active ones only.
	 *
	 * @param array $plans Reduced plans.
	 * @return array<int, array<string, mixed>>
	 */
	public static function selectable( array $plans ): array {
		return array_values(
			array_filter(
				$plans,
				static function ( $plan ) {
					return ! empty( $plan['active'] );
				}
			)
		);
	}

	/**
	 * Annotates a stored policy's tier ids against the catalogue.
	 *
	 * A selected plan that has been deleted or deactivated in Agend is returned
	 * as `available => false` so the editor can see and repair it. It is never
	 * silently dropped and never converted to public: dropping it would quietly
	 * change who can read the content, which is the failure mode Decision 2.12
	 * exists to prevent.
	 *
	 * @param string[] $tier_ids Stored tier ids.
	 * @param array    $plans    Reduced plans.
	 * @return array<int, array{id: string, name: string, available: bool, active: bool}>
	 */
	public static function annotate_selection( array $tier_ids, array $plans ): array {
		$by_id = array();

		foreach ( $plans as $plan ) {
			$by_id[ $plan['id'] ] = $plan;
		}

		$annotated = array();

		foreach ( $tier_ids as $tier_id ) {
			$tier_id = (string) $tier_id;

			if ( isset( $by_id[ $tier_id ] ) ) {
				$annotated[] = array(
					'id'        => $tier_id,
					'name'      => $by_id[ $tier_id ]['name'],
					'available' => ! empty( $by_id[ $tier_id ]['active'] ),
					'active'    => ! empty( $by_id[ $tier_id ]['active'] ),
				);
				continue;
			}

			$annotated[] = array(
				'id'        => $tier_id,
				'name'      => '',
				'available' => false,
				'active'    => false,
			);
		}

		return $annotated;
	}

	/**
	 * Whether a NEW selected-plans policy may be saved right now.
	 *
	 * False only when there is no catalogue at all, which means we cannot tell
	 * whether a submitted tier id is real. Public and all-active-members remain
	 * saveable in that state, so an outage never blocks editing outright, only
	 * the one operation that needs data we do not have.
	 *
	 * @param array $catalogue Result of `get()`.
	 * @return bool
	 */
	public static function can_select_plans( array $catalogue ): bool {
		return ! empty( $catalogue['plans'] );
	}
}
