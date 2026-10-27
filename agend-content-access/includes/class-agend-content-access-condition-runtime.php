<?php
/**
 * Binds the condition engine to real fact sources.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The request-scoped runtime for display conditions
 * (SPEC-CMS-20260727 US-6.1, reframed as ESAC feature parity).
 *
 * Wires the three condition families to where their answers actually come
 * from, and holds them for the life of the request.
 *
 * | Family              | Source                                   |
 * | ------------------- | ---------------------------------------- |
 * | `agend_wordpress`   | current user, locally, no network         |
 * | `agend_membership`  | `Agend_Content_Access_Viewer_Facts`, cached |
 * | `agend_segments`    | `GET /v1/crm/me/segments`, cached         |
 *
 * Membership facts come from `Agend_Content_Access_Viewer_Facts`, the one
 * source it shares with the document policy path, so a page asks
 * `GET /v1/crm/me/entitlements` once however many things on it need the answer.
 * `member_facts()` here is a thin adapter over it that keeps this path's own
 * rule for a signed-out visitor.
 *
 * Each API-backed family is cached PER VIEWER, never globally: both responses
 * are entirely about who is asking, so a shared entry would hand one member's
 * standing or segments to the next visitor. Both caches are keyed by the same
 * generation number, so a single bump invalidates them together.
 *
 * A source that cannot answer returns null, which the engine reads as unknown
 * and denies on while naming the provider. The one case that answers
 * definitively is a signed-out visitor, who certainly holds no membership and
 * belongs to no segments; that needs no network.
 */
class Agend_Content_Access_Condition_Runtime {

	/**
	 * Cache key prefixes, aliased from the facts source that owns the keys.
	 * Build a full key with `Agend_Content_Access_Viewer_Facts::member_cache_key()`
	 * or `::segment_cache_key()`, which add the user id and the generation.
	 */
	const MEMBER_CACHE_KEY  = Agend_Content_Access_Viewer_Facts::MEMBER_KEY_PREFIX;
	const SEGMENT_CACHE_KEY = Agend_Content_Access_Viewer_Facts::SEGMENT_KEY_PREFIX;

	/**
	 * Builds a checker bound to live sources.
	 *
	 * @return callable fn(string $provider, string $value): ?bool
	 */
	public static function checker(): callable {
		return Agend_Content_Access_Condition_Providers::checker(
			array(
				Agend_Content_Access_Condition_Providers::WORDPRESS  => array( 'Agend_Content_Access_Condition_Providers', 'wordpress_facts' ),
				Agend_Content_Access_Condition_Providers::MEMBERSHIP => array( __CLASS__, 'member_facts' ),
				Agend_Content_Access_Condition_Providers::SEGMENTS   => array( __CLASS__, 'segment_facts' ),
			)
		);
	}

	/**
	 * The current visitor's membership standing.
	 *
	 * A thin adapter over `Agend_Content_Access_Viewer_Facts::member()`, which
	 * memoises the lookup for the request and caches it per VIEWER, never
	 * globally. The response is entirely about who is asking, so a shared cache
	 * entry would hand one member's standing to the next visitor. That exact leak
	 * was found and fixed in Core's `get_cached()` earlier in this work, and it is
	 * not worth re-earning.
	 *
	 * Returns null on any failure, which the engine reads as unknown and denies
	 * on. Returning "not a member" instead would make an API outage
	 * indistinguishable from a genuine non-member, and on an inverted rule it
	 * would disclose rather than deny.
	 *
	 * This path's rule for a signed-out visitor is its own: not logged in means
	 * holds nothing, definitively, with no lookup. The document path keys on a
	 * bearer being present instead, and keeps its own rule.
	 *
	 * @return array{is_member: bool, tier_ids: string[], tier_slugs: string[]}|null
	 */
	public static function member_facts(): ?array {
		if ( ! is_user_logged_in() ) {
			// Not an outage: a signed-out visitor definitively holds nothing.
			return array( 'is_member' => false, 'tier_ids' => array(), 'tier_slugs' => array() );
		}

		$facts = Agend_Content_Access_Viewer_Facts::member();

		if ( null === $facts ) {
			return null;
		}

		// The three fields this path has always returned. The source holds more
		// (entitlements), which nothing here reads yet.
		return array(
			'is_member'  => $facts['is_member'],
			'tier_ids'   => $facts['tier_ids'],
			'tier_slugs' => $facts['tier_slugs'],
		);
	}

	/**
	 * Segment membership for the current visitor.
	 *
	 * Reads `GET /v1/crm/me/segments`, which answers for the bearer alone. The
	 * admin `/crm/segments/{id}/contacts` route is deliberately not used: it
	 * lists who is in a segment, so answering a question about one visitor with
	 * it would pull the tenant's whole member list onto this server.
	 *
	 * Cached per viewer for the same reason membership facts are, under the same
	 * generation so one bump clears both, and a failure returns null so the
	 * engine denies and reports rather than treating the visitor as belonging to
	 * no segments, which is a different claim.
	 *
	 * @return string[]|null Segment slugs, or null when unknown.
	 */
	public static function segment_facts(): ?array {
		/**
		 * Filters segment membership for the current visitor.
		 *
		 * Kept so a site can supply segments from its own source. Returning an
		 * array short-circuits the gateway read entirely.
		 *
		 * @param string[]|null $segments Segment slugs the visitor belongs to.
		 */
		$supplied = apply_filters( 'agend_content_access_segment_facts', null );

		if ( is_array( $supplied ) ) {
			return array_values( array_map( 'strval', $supplied ) );
		}

		if ( ! is_user_logged_in() ) {
			// Definitive: a signed-out visitor belongs to no segments. Segments
			// are contact attributes, and there is no contact.
			return array();
		}

		if ( ! function_exists( 'agend_apps_crm_get_my_segments' ) ) {
			return null;
		}

		$cache_key = Agend_Content_Access_Viewer_Facts::segment_cache_key( get_current_user_id() );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = agend_apps_crm_get_my_segments();

		if ( is_wp_error( $response ) ) {
			// Not cached: a blip must not become a TTL-long outage.
			return null;
		}

		$rows = isset( $response['data'] ) && is_array( $response['data'] )
			? $response['data']
			: $response;

		if ( ! is_array( $rows ) ) {
			return null;
		}

		$slugs = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['slug'] ) && '' !== (string) $row['slug'] ) {
				$slugs[] = (string) $row['slug'];
			}
		}

		set_transient( $cache_key, $slugs, self::cache_ttl() );

		return $slugs;
	}

	/**
	 * TTL for the cached membership and segment facts.
	 *
	 * Short by intent. Membership standing decides what a visitor can see, so a
	 * long TTL means a lapsed member keeps their access for that long. Uses
	 * Core's configured value when available so an operator tunes one setting.
	 * The rule lives on the facts source; this stays as the stable entry point
	 * the segment cache and the vocabulary cache already call.
	 *
	 * @return int Seconds.
	 */
	public static function cache_ttl(): int {
		return Agend_Content_Access_Viewer_Facts::cache_ttl();
	}

	/**
	 * Clears the cached facts for one user.
	 *
	 * Called when membership changes arrive by webhook, so an upgrade or a
	 * lapse takes effect without waiting out the TTL. Clears the membership
	 * entry and the segment entry, under the current generation.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function flush_member_facts( int $user_id ): void {
		Agend_Content_Access_Viewer_Facts::flush( $user_id );
	}
}
