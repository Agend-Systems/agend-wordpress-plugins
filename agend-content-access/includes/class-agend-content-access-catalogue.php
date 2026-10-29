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
 * Supplies the editor's pickers, server-side and reduced: membership plans,
 * segments and entitlement types.
 *
 * Three properties this class exists to guarantee (SPEC-CMS-20260727 US-3.3):
 *
 * 1. The tenant API key never reaches the browser. Each list is fetched on the
 *    WordPress server through Core's wrapper and only the reduced result is
 *    handed to the editor.
 * 2. The editor receives no pricing, seat, member-count or benefit data.
 *    `GET /v1/crm/tiers` returns all of it; the reduction happens here, in PHP,
 *    rather than by adding a gateway endpoint (Decision 2.13). The segment and
 *    entitlement lists are reduced the same way, by copying named fields.
 * 3. An Agend outage never widens a policy. A failed refresh keeps the editor
 *    working with the last known list, and where there is none at all it blocks
 *    NEW selections rather than presenting an empty picker that an editor could
 *    read as "no plans exist".
 *
 * The three lists fail independently. An API key without the entitlement scope
 * still gets plans and segments, and the one list that failed says why in words
 * an editor can act on. Every consumer reads the same cached copy: the page
 * panel, the REST route, the Elementor controls and the display condition
 * vocabularies, so there is one cache and one gateway call per list.
 *
 * Each list is also asked of Agend at most ONCE PER REQUEST, an answer that was
 * a failure included. The transient cannot say that on its own, because a
 * failure is never written to it (see `section()`), so without a request-scoped
 * memo every caller during an outage would ask the gateway again. The one that
 * matters is a listing: the gate for each restricted post names the plans or
 * entitlements it admits, so a page of twenty of them would make twenty failing
 * calls, each as slow as a timeout. The memo lives in memory for the request and
 * is never persisted, so a failure still cannot outlast the request that saw it.
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
	 * Transients holding the reduced segment and entitlement lists.
	 *
	 * Named for the catalogue, not for the vocabularies that used to keep their
	 * own copies (`..._segment_vocabulary` and `..._entitlement_vocabulary`).
	 * Those held a slug-to-label map; these hold a list of items, so reusing the
	 * old names would have an upgraded site read a value of the wrong shape until
	 * it expired. The old entries are simply never read again and age out.
	 *
	 * @var string
	 */
	const SEGMENTS_TRANSIENT     = 'agend_content_access_segment_catalogue';
	const ENTITLEMENTS_TRANSIENT = 'agend_content_access_entitlement_catalogue';

	/**
	 * Core cache key whose configured TTL this reuses, so an administrator
	 * tuning tier caching gets the expected behaviour here too.
	 *
	 * @var string
	 */
	const TTL_KEY = 'crm_tiers';

	/**
	 * The two lists that come from a Core wrapper which may be missing or
	 * refused, as opposed to plans, which have their own long-standing path.
	 *
	 * @var string
	 */
	const SECTION_SEGMENTS     = 'segments';
	const SECTION_ENTITLEMENTS = 'entitlements';

	/**
	 * The Agend Apps Core release that carries `agend_apps_crm_get_entitlement_types()`,
	 * named in the message an editor sees when Core is older. Segments are
	 * reported against the same release because the plugin is built and shipped
	 * to run with it.
	 *
	 * @var string
	 */
	const CORE_MINIMUM = '1.48.0';

	/**
	 * Key of the plan list in the per-request memo. The other two lists use their
	 * `SECTION_*` constants.
	 *
	 * @var string
	 */
	private const MEMO_PLANS = 'plans';

	/**
	 * What each list answered this request, keyed by list: the same array its
	 * public method returns, a failure included.
	 *
	 * Held in memory only. A transient would carry a failure beyond the request,
	 * which `section()` rules out. A forced refresh bypasses it and replaces the
	 * entry with what it learned.
	 *
	 * @var array<string, array>
	 */
	private static array $memo = array();

	/**
	 * Forgets what each list answered this request. Tests only: in production one
	 * request has one answer per list, which is the point of holding it.
	 */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * Returns the plan catalogue for the editor.
	 *
	 * Asked of Agend at most once per request, a failure included. See the class
	 * docblock.
	 *
	 * @param bool $force_refresh Optional. Bypass the request memo and the local transient. Default false.
	 * @return array{
	 *     plans: array<int, array{id: string, name: string, slug: string, type: string, active: bool}>,
	 *     stale: bool,
	 *     error: string
	 * } Plans, whether they came from a stale cache after a failed refresh, and
	 *   a human-readable error when one occurred.
	 */
	public static function get( bool $force_refresh = false ): array {
		if ( ! $force_refresh && isset( self::$memo[ self::MEMO_PLANS ] ) ) {
			return self::$memo[ self::MEMO_PLANS ];
		}

		self::$memo[ self::MEMO_PLANS ] = self::load_plans( $force_refresh );

		return self::$memo[ self::MEMO_PLANS ];
	}

	/**
	 * Reads the plan list from the transient, then from the gateway.
	 *
	 * @param bool $force_refresh Bypass the local transient.
	 * @return array{plans: array, stale: bool, error: string}
	 */
	private static function load_plans( bool $force_refresh ): array {
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

	/**
	 * All three lists, each in the one section shape the editor consumes.
	 *
	 * Every section is `{items, stale, error}`: the reduced rows, whether they
	 * came from a stale copy after a failed refresh, and a message an editor can
	 * act on when one occurred. The plans section carries ALL plans with their
	 * `active` flag rather than only the offerable ones, so the caller can both
	 * offer the active ones and recognise an inactive plan a policy still names.
	 *
	 * @param bool $force_refresh Optional. Bypass the request memo and every local transient. Default false.
	 * @return array{
	 *     plans: array{items: array<int, array<string, mixed>>, stale: bool, error: string},
	 *     segments: array{items: array<int, array{id: string, slug: string, name: string}>, stale: bool, error: string},
	 *     entitlements: array{items: array<int, array{gate_key: string, name: string, description: string, kind: string}>, stale: bool, error: string}
	 * }
	 */
	public static function sections( bool $force_refresh = false ): array {
		$plans = self::get( $force_refresh );

		return array(
			'plans'        => array(
				'items' => $plans['plans'],
				'stale' => $plans['stale'],
				'error' => $plans['error'],
			),
			'segments'     => self::segments( $force_refresh ),
			'entitlements' => self::entitlements( $force_refresh ),
		);
	}

	/**
	 * The segment list for the editor.
	 *
	 * Read through the admin segments endpoint, which is the right one HERE: an
	 * editor legitimately needs the list of segments that exist. It is
	 * emphatically not the endpoint used to decide what a visitor can see, which
	 * asks only about the caller.
	 *
	 * @param bool $force_refresh Optional. Bypass the request memo and the local transient. Default false.
	 * @return array{items: array<int, array{id: string, slug: string, name: string}>, stale: bool, error: string}
	 */
	public static function segments( bool $force_refresh = false ): array {
		return self::section( self::SECTION_SEGMENTS, $force_refresh );
	}

	/**
	 * The entitlement type list for the editor.
	 *
	 * Read through Core's `agend_apps_crm_get_entitlement_types()`, probed with
	 * `function_exists()` and NOT added to the hard dependency list. A Core that
	 * predates the wrapper then degrades to an empty list with a message, so an
	 * editor loses the picker rather than the whole plugin switching protection
	 * off.
	 *
	 * @param bool $force_refresh Optional. Bypass the request memo and the local transient. Default false.
	 * @return array{items: array<int, array{gate_key: string, name: string, description: string, kind: string}>, stale: bool, error: string}
	 */
	public static function entitlements( bool $force_refresh = false ): array {
		return self::section( self::SECTION_ENTITLEMENTS, $force_refresh );
	}

	/**
	 * Loads one of the two Core-wrapper lists, cached, with the plans' failure
	 * rules.
	 *
	 * A failure is NEVER cached: not an outage, not a refusal, not an old Core.
	 * Caching one would keep the picker empty for the cache lifetime after the
	 * cause was fixed, when the point of the message is that an editor can fix it
	 * and see the list fill. What a failure does instead is serve the last known
	 * list, marked stale, so an outage does not empty a picker an editor is
	 * halfway through using.
	 *
	 * What it answered is held for the rest of the request, a failure included:
	 * the memo is where "never cached" stops short of "asked again for every
	 * caller". It is in memory only, so the failure is gone with the request.
	 *
	 * @param string $kind          SECTION_SEGMENTS or SECTION_ENTITLEMENTS.
	 * @param bool   $force_refresh Bypass the request memo and the local transient.
	 * @return array{items: array<int, array<string, string>>, stale: bool, error: string}
	 */
	private static function section( string $kind, bool $force_refresh ): array {
		if ( ! $force_refresh && isset( self::$memo[ $kind ] ) ) {
			return self::$memo[ $kind ];
		}

		self::$memo[ $kind ] = self::load_section( $kind, $force_refresh );

		return self::$memo[ $kind ];
	}

	/**
	 * Reads one of the two Core-wrapper lists from the transient, then from the
	 * gateway. The rules are `section()`'s.
	 *
	 * @param string $kind          SECTION_SEGMENTS or SECTION_ENTITLEMENTS.
	 * @param bool   $force_refresh Bypass the local transient.
	 * @return array{items: array<int, array<string, string>>, stale: bool, error: string}
	 */
	private static function load_section( string $kind, bool $force_refresh ): array {
		$transient = self::SECTION_SEGMENTS === $kind
			? self::SEGMENTS_TRANSIENT
			: self::ENTITLEMENTS_TRANSIENT;

		if ( ! $force_refresh ) {
			$cached = get_transient( $transient );

			if ( is_array( $cached ) ) {
				return array(
					'items' => $cached,
					'stale' => false,
					'error' => '',
				);
			}
		}

		$available = self::has_wrapper( $kind );
		$response  = $available ? self::fetch( $kind ) : null;
		$rows      = $available ? self::rows_of( $response ) : null;

		if ( null === $rows ) {
			$fallback = get_transient( $transient );

			return array(
				'items' => is_array( $fallback ) ? $fallback : array(),
				'stale' => true,
				'error' => self::section_error( $kind, $available, $response ),
			);
		}

		$items = self::SECTION_SEGMENTS === $kind
			? self::reduce_segments( $rows )
			: self::reduce_entitlements( $rows );

		set_transient( $transient, $items, Agend_Content_Access_Condition_Runtime::cache_ttl() );

		return array(
			'items' => $items,
			'stale' => false,
			'error' => '',
		);
	}

	/**
	 * Whether this Core has the wrapper for a list.
	 *
	 * A separate question from what the wrapper answered, so that a wrapper
	 * which returns nothing usable is reported as a failed load and not as an
	 * out-of-date Core.
	 *
	 * @param string $kind SECTION_SEGMENTS or SECTION_ENTITLEMENTS.
	 * @return bool
	 */
	private static function has_wrapper( string $kind ): bool {
		return function_exists(
			self::SECTION_SEGMENTS === $kind
				? 'agend_apps_crm_get_segments'
				: 'agend_apps_crm_get_entitlement_types'
		);
	}

	/**
	 * Calls the Core wrapper for a list. Only called once `has_wrapper()` is true.
	 *
	 * @param string $kind SECTION_SEGMENTS or SECTION_ENTITLEMENTS.
	 * @return mixed The wrapper's response: a decoded body or a WP_Error.
	 */
	private static function fetch( string $kind ) {
		return self::SECTION_SEGMENTS === $kind
			? agend_apps_crm_get_segments()
			: agend_apps_crm_get_entitlement_types();
	}

	/**
	 * The rows of a wrapper response, or null when it is not a usable success.
	 *
	 * The gateway answers `{success, data: [...]}`. Anything else is a failure
	 * and is never cached: a WP_Error, a body that is not an array, a `success`
	 * of false, or a 2xx with no `data` list. The last matters most. Reading a
	 * body we do not recognise as "there are none" would be cached and would
	 * tell an editor the tenant has no segments, which is a different and
	 * misleading claim from "we could not read the list".
	 *
	 * @param mixed $response Wrapper response.
	 * @return array|null Rows, or null on failure.
	 */
	private static function rows_of( $response ): ?array {
		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			return null;
		}

		if ( isset( $response['success'] ) && true !== $response['success'] ) {
			return null;
		}

		return isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: null;
	}

	/**
	 * The message an editor sees when a list could not be loaded.
	 *
	 * Three causes, each with a different fix, so each gets its own sentence
	 * rather than the transport message Core reports:
	 *
	 *   - Core predates the wrapper: update Core.
	 *   - The gateway, or Core's own pre-flight check of the key's scopes,
	 *     refused with a 403: the site's API key lacks the scope. Core reports an
	 *     HTTP status as `status_code` in the WP_Error data, for a live response
	 *     and a local refusal alike.
	 *   - Anything else: the list could not be loaded, and trying again may help.
	 *
	 * The raw message is deliberately not passed through. It can carry a gateway
	 * URL or transport detail that means nothing to an editor and does not belong
	 * on a page they can screenshot.
	 *
	 * @param string $kind      SECTION_SEGMENTS or SECTION_ENTITLEMENTS.
	 * @param bool   $available Whether Core has the wrapper.
	 * @param mixed  $response  Wrapper response that failed, when it was called.
	 * @return string
	 */
	private static function section_error( string $kind, bool $available, $response ): string {
		$segments = self::SECTION_SEGMENTS === $kind;

		if ( ! $available ) {
			return $segments
				? sprintf(
					/* translators: %s: Agend Apps Core version number. */
					__( 'Agend Apps Core %s or later is needed to list segments. Update Agend Apps Core on this site.', 'agend-content-access' ),
					self::CORE_MINIMUM
				)
				: sprintf(
					/* translators: %s: Agend Apps Core version number. */
					__( 'Agend Apps Core %s or later is needed to list entitlements. Update Agend Apps Core on this site.', 'agend-content-access' ),
					self::CORE_MINIMUM
				);
		}

		if ( is_wp_error( $response ) && 403 === self::status_of( $response ) ) {
			return $segments
				? __( 'This site\'s Agend API key needs the crm.segments.browse scope before segments can be listed. Add the scope to the key in Agend.', 'agend-content-access' )
				: __( 'This site\'s Agend API key needs the crm.entitlements.browse scope before entitlements can be listed. Add the scope to the key in Agend.', 'agend-content-access' );
		}

		return $segments
			? __( 'The list of segments could not be loaded from Agend. Try again in a few minutes.', 'agend-content-access' )
			: __( 'The list of entitlements could not be loaded from Agend. Try again in a few minutes.', 'agend-content-access' );
	}

	/**
	 * The HTTP status Core recorded on a WP_Error, or 0.
	 *
	 * @param WP_Error $error Error returned by a Core wrapper.
	 * @return int
	 */
	private static function status_of( $error ): int {
		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data['status_code'] )
			? (int) $data['status_code']
			: 0;
	}

	/**
	 * Reduces the gateway segment payload to the editor contract.
	 *
	 * A row without an id that has the UUID shape a policy stores is skipped: a
	 * policy can only ever hold a UUID, so offering anything else is a choice
	 * that could never be saved. The slug is kept for the display condition
	 * vocabulary, which has always keyed segments by it for compatibility with
	 * stored conditions; a row with no slug is still offered to a policy.
	 *
	 * @param array $rows `data` of `GET /v1/crm/segments`.
	 * @return array<int, array{id: string, slug: string, name: string}>
	 */
	private static function reduce_segments( array $rows ): array {
		$segments = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row )
				|| ! isset( $row['id'] )
				|| ! is_string( $row['id'] )
				|| 1 !== preg_match( Agend_Content_Access_Policy::UUID_PATTERN, $row['id'] ) ) {
				continue;
			}

			$slug = isset( $row['slug'] ) && is_string( $row['slug'] ) ? trim( $row['slug'] ) : '';
			$name = isset( $row['name'] ) && is_string( $row['name'] ) ? trim( $row['name'] ) : '';

			$segments[] = array(
				'id'   => $row['id'],
				'slug' => $slug,
				// Never blank: a label an editor cannot read is not a choice.
				'name' => '' !== $name ? $name : ( '' !== $slug ? $slug : $row['id'] ),
			);
		}

		return $segments;
	}

	/**
	 * Reduces the gateway entitlement type payload to the editor contract.
	 *
	 * The rows are `{id, gate_key, name, description, managed_by_source_id,
	 * kind}`. The row id and the managing source are internal and are not
	 * copied. `kind` is `platform-gate` (a capability the platform itself
	 * defines, Directory access say), `entitlement` (one an administrator
	 * authored) or `unrecognised` (a benefit whose key is neither a platform
	 * gate nor in the reserved entitlement namespace). An `unrecognised` key is
	 * kept and offered under its own name so a holder of it can still be
	 * targeted and nothing is silently dropped. A `kind` that is none of those
	 * is kept as an empty string.
	 *
	 * A row without a well-formed gate key is skipped. A visitor's held keys are
	 * filtered through the same pattern, so a key that fails it could never
	 * match a condition, and a policy cannot store one, so offering it would be
	 * a choice that can never pass.
	 *
	 * @param array $rows `data` of `GET /v1/crm/entitlements/types`.
	 * @return array<int, array{gate_key: string, name: string, description: string, kind: string}>
	 */
	private static function reduce_entitlements( array $rows ): array {
		$entitlements = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row )
				|| ! isset( $row['gate_key'] )
				|| ! is_string( $row['gate_key'] )
				|| 1 !== preg_match( Agend_Content_Access_Policy::ENTITLEMENT_KEY_PATTERN, $row['gate_key'] ) ) {
				continue;
			}

			$name = isset( $row['name'] ) && is_string( $row['name'] ) ? trim( $row['name'] ) : '';
			$kind = isset( $row['kind'] ) && is_string( $row['kind'] ) ? $row['kind'] : '';

			$entitlements[] = array(
				'gate_key'    => $row['gate_key'],
				'name'        => '' !== $name ? $name : $row['gate_key'],
				'description' => isset( $row['description'] ) && is_string( $row['description'] ) ? trim( $row['description'] ) : '',
				'kind'        => in_array( $kind, array( 'platform-gate', 'entitlement', 'unrecognised' ), true ) ? $kind : '',
			);
		}

		return $entitlements;
	}

	/**
	 * An entitlement type's label, with platform capabilities marked.
	 *
	 * One flat list holds both capabilities the platform defines and ones an
	 * administrator authored, and an editor should be able to tell them apart at
	 * a glance, so a platform gate reads "Platform: Directory access". Shared by
	 * the page panel, the Elementor control and the display condition
	 * vocabulary, so the same entitlement is called the same thing everywhere.
	 *
	 * @param array $entitlement An item of the entitlements section.
	 * @return string
	 */
	public static function entitlement_label( array $entitlement ): string {
		$name = isset( $entitlement['name'] ) && '' !== (string) $entitlement['name']
			? (string) $entitlement['name']
			: (string) ( $entitlement['gate_key'] ?? '' );

		if ( isset( $entitlement['kind'] ) && 'platform-gate' === $entitlement['kind'] ) {
			return sprintf(
				/* translators: %s: platform capability name, for example Directory access. */
				__( 'Platform: %s', 'agend-content-access' ),
				$name
			);
		}

		return $name;
	}

	/**
	 * A section's items in one shape for a picker, whatever list they came from.
	 *
	 * `value` is what a policy stores (a plan or segment id, an entitlement
	 * key), `label` is what the editor reads, and `available` is whether it may
	 * be chosen NEW. Only plans can be unavailable while still listed, because
	 * only plans carry an `active` flag: a deactivated plan stays known so a
	 * policy that names it can be shown and repaired.
	 *
	 * @param string $mode  A set-mode of `Agend_Content_Access_Policy`.
	 * @param array  $items The section's items.
	 * @return array<int, array{value: string, label: string, description: string, available: bool}>
	 */
	public static function options( string $mode, array $items ): array {
		$options = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( Agend_Content_Access_Policy::MODE_TIERS === $mode ) {
				$options[] = array(
					'value'       => (string) ( $item['id'] ?? '' ),
					'label'       => (string) ( $item['name'] ?? '' ),
					'description' => '',
					'available'   => ! empty( $item['active'] ),
				);
			} elseif ( Agend_Content_Access_Policy::MODE_SEGMENTS === $mode ) {
				$options[] = array(
					'value'       => (string) ( $item['id'] ?? '' ),
					'label'       => (string) ( $item['name'] ?? '' ),
					'description' => '',
					'available'   => true,
				);
			} elseif ( Agend_Content_Access_Policy::MODE_ENTITLEMENTS === $mode ) {
				$options[] = array(
					'value'       => (string) ( $item['gate_key'] ?? '' ),
					'label'       => self::entitlement_label( $item ),
					'description' => (string) ( $item['description'] ?? '' ),
					'available'   => true,
				);
			}
		}

		return $options;
	}

	/**
	 * Annotates a stored selection against a picker's options.
	 *
	 * The generic form of `annotate_selection()`: a stored value that is no
	 * longer offered, or no longer offerable, is returned as `available => false`
	 * so the editor can see and repair it, and is never dropped. Dropping it
	 * would quietly change who can read the content.
	 *
	 * @param string[] $values  Stored selection.
	 * @param array    $options Result of `options()`.
	 * @return array<int, array{value: string, label: string, available: bool}>
	 */
	public static function annotate_options( array $values, array $options ): array {
		$by_value = array();

		foreach ( $options as $option ) {
			$by_value[ $option['value'] ] = $option;
		}

		$annotated = array();

		foreach ( $values as $value ) {
			$value = (string) $value;

			$annotated[] = isset( $by_value[ $value ] )
				? array(
					'value'     => $value,
					'label'     => $by_value[ $value ]['label'],
					'available' => $by_value[ $value ]['available'],
				)
				: array(
					'value'     => $value,
					'label'     => '',
					'available' => false,
				);
		}

		return $annotated;
	}
}
