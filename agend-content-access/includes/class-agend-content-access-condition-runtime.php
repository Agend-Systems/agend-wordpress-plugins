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
 * The request-scoped runtime for access rules
 * (SPEC-CMS-20260727 US-6.1, reframed as ESAC feature parity).
 *
 * Wires the condition families to where their answers actually come from, and
 * holds them for the life of the request. `Agend_Content_Access_Access` is the one
 * caller: every rule set, on a block, a section or a page, is judged against the
 * checker built here.
 *
 * WHO IS SIGNED IN, FOR AGEND, IS WHO HOLDS A BEARER. A visitor with no bearer is
 * definitively nobody to Agend and nothing is looked up for them; a visitor with
 * one is whoever the gateway says. It is what the page gate has always used, and
 * what a rule on a page, a block and a section now all share: a member of Agend
 * need not be signed in to WordPress, and a WordPress login with no linked member
 * session is not a membership. WordPress's own login state answers only the
 * `agend_wordpress` family.
 *
 * | Family               | Source                                         |
 * | -------------------- | ---------------------------------------------- |
 * | `agend_wordpress`    | current user, locally, no network              |
 * | `agend_membership`   | `Agend_Content_Access_Viewer_Facts`, cached    |
 * | `agend_segments`     | `Agend_Content_Access_Viewer_Facts`, on demand |
 * | `agend_entitlements` | `Agend_Content_Access_Viewer_Facts`, cached    |
 *
 * Membership facts come from `Agend_Content_Access_Viewer_Facts`, the one
 * source every rule shares, so a page asks `GET /v1/crm/me/entitlements` once
 * however many things on it need the answer. `member_facts()` here is a thin
 * adapter over it.
 *
 * Each API-backed family is cached PER VIEWER, never globally: both responses
 * are entirely about who is asking, so a shared entry would hand one member's
 * standing or segments to the next visitor. The keys (built by the facts source)
 * carry the user id, a digest of the bearer and the generations, so a single bump
 * or flush invalidates both families together.
 *
 * A source that cannot answer returns null, which the engine reads as unknown
 * and denies on while naming the provider. The one case that answers
 * definitively is a visitor with no bearer, who certainly holds no membership,
 * belongs to no segments and holds no entitlements; that needs no network.
 */
class Agend_Content_Access_Condition_Runtime {

	/**
	 * Builds a checker bound to live sources.
	 *
	 * @return callable fn(string $provider, string $value): ?bool
	 */
	public static function checker(): callable {
		return Agend_Content_Access_Condition_Providers::checker(
			array(
				Agend_Content_Access_Condition_Providers::WORDPRESS    => array( 'Agend_Content_Access_Condition_Providers', 'wordpress_facts' ),
				Agend_Content_Access_Condition_Providers::MEMBERSHIP   => array( __CLASS__, 'member_facts' ),
				Agend_Content_Access_Condition_Providers::SEGMENTS     => array( __CLASS__, 'segment_sets' ),
				Agend_Content_Access_Condition_Providers::ENTITLEMENTS => array( __CLASS__, 'entitlement_facts' ),
			)
		);
	}

	/**
	 * Builds a checker for a visitor who is nobody.
	 *
	 * For output that is shared and cached whoever asked, a syndication feed: it
	 * must read the same for everybody, so a condition cannot be answered for the
	 * person who happened to fetch it. Every family answers definitively, as the
	 * live checker does for a signed-out visitor: not logged in, no roles, not a
	 * member, no plans, no segments, no entitlements. Nothing is looked up, so
	 * there is no bearer, no gateway call and nothing to fail, and the
	 * `agend_content_access_segment_facts` filter, which supplies segments for the
	 * CURRENT visitor, is not consulted.
	 *
	 * A provider this plugin does not define is still answered by its own filter,
	 * which is told nothing about the visitor and so has nothing to leak.
	 *
	 * @return callable fn(string $provider, string $value): ?bool
	 */
	public static function anonymous_checker(): callable {
		return Agend_Content_Access_Condition_Providers::checker(
			array(
				Agend_Content_Access_Condition_Providers::WORDPRESS    => static function (): array {
					return array( 'logged_in' => false, 'roles' => array() );
				},
				Agend_Content_Access_Condition_Providers::MEMBERSHIP   => static function (): array {
					return array( 'is_member' => false, 'tier_ids' => array(), 'tier_slugs' => array() );
				},
				Agend_Content_Access_Condition_Providers::SEGMENTS     => static function (): array {
					return array();
				},
				Agend_Content_Access_Condition_Providers::ENTITLEMENTS => static function (): array {
					return array();
				},
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
	 * A visitor with no bearer holds nothing, definitively and with no lookup. This
	 * used to key on a WordPress login while the document path keyed on a bearer,
	 * two answers to the same question. Now that one evaluator judges both, there is
	 * one: the bearer. See the class docblock.
	 *
	 * @return array{is_member: bool, tier_ids: string[], tier_slugs: string[]}|null
	 */
	public static function member_facts(): ?array {
		if ( ! self::has_bearer() ) {
			// Not an outage: a visitor Agend cannot identify definitively holds nothing.
			return array( 'is_member' => false, 'tier_ids' => array(), 'tier_slugs' => array() );
		}

		$facts = Agend_Content_Access_Viewer_Facts::member();

		if ( null === $facts ) {
			return null;
		}

		// The three fields this path has always returned. The source holds more
		// (entitlements), which has its own adapter.
		//
		// A MEMBER IS SOMEBODY THE GATEWAY CALLS ONE AND WHO HOLDS A PLAN. The page gate
		// decided membership by the plans a visitor held and display conditions by the
		// gateway's `is_member` flag. Both come from the one member definition, so they
		// agree on a real answer, but this is the single place that definition is read
		// now, and where the two could ever differ the visitor is not a member: a
		// reading that grants on either alone could only ever widen what the stricter
		// one admitted.
		return array(
			'is_member'  => $facts['is_member'] && array() !== $facts['tier_ids'],
			'tier_ids'   => $facts['tier_ids'],
			'tier_slugs' => $facts['tier_slugs'],
		);
	}

	/**
	 * The entitlements the current visitor holds, as gate keys.
	 *
	 * Another thin adapter over `Agend_Content_Access_Viewer_Facts::member()`:
	 * entitlement keys come from the same `/crm/me/entitlements` response as the
	 * membership facts, so this costs no call a page was not already making, and
	 * the document path and this one share the answer.
	 *
	 * A visitor with no bearer holds none, definitively and with no lookup. Anything
	 * the source cannot answer (the Core wrapper is missing, the gateway failed,
	 * the response was malformed) is null, which the engine denies on and
	 * reports. Never an empty list: "holds nothing" and "could not tell" are
	 * different claims, and a rule may be inverted.
	 *
	 * @return string[]|null Gate keys, or null when unknown.
	 */
	public static function entitlement_facts(): ?array {
		if ( ! self::has_bearer() ) {
			// Not an outage: a visitor Agend cannot identify definitively holds nothing.
			return array();
		}

		$facts = Agend_Content_Access_Viewer_Facts::member();

		return null === $facts ? null : $facts['entitlement_keys'];
	}

	/**
	 * Segment membership for the current visitor, as ids and slugs.
	 *
	 * A thin adapter over `Agend_Content_Access_Viewer_Facts::segments()`, which
	 * owns the gateway read, the per-viewer cache and the failure rules. This
	 * keeps what is specific to rules: a rule names a segment by id (new rules and
	 * every converted page policy) or by slug (stored ESAC conditions), a site can
	 * supply segments through a filter, and a visitor with no bearer belongs to none
	 * without any lookup.
	 *
	 * A failure returns null so the engine denies and reports rather than
	 * treating the visitor as belonging to no segments, which is a different
	 * claim.
	 *
	 * @return array{segment_ids: string[], segment_slugs: string[]}|null Null when unknown.
	 */
	public static function segment_sets(): ?array {
		/**
		 * Filters segment membership for the current visitor.
		 *
		 * Kept so a site can supply segments from its own source. Returning an
		 * array short-circuits the gateway read entirely. It supplies SLUGS,
		 * which is what stored display conditions match on, so it satisfies a rule
		 * that names a slug and never one that names an id, and an id rule, which is
		 * what a converted page policy is, is denied while it is in use.
		 *
		 * @param string[]|null $segments Segment slugs the visitor belongs to.
		 */
		$supplied = apply_filters( 'agend_content_access_segment_facts', null );

		if ( is_array( $supplied ) ) {
			return array(
				'segment_ids'   => array(),
				'segment_slugs' => array_values( array_map( 'strval', $supplied ) ),
			);
		}

		if ( ! self::has_bearer() ) {
			// Definitive: a visitor Agend cannot identify belongs to no segments.
			// Segments are contact attributes, and there is no contact.
			return array(
				'segment_ids'   => array(),
				'segment_slugs' => array(),
			);
		}

		return Agend_Content_Access_Viewer_Facts::segments();
	}

	/**
	 * Whether Agend can identify the current visitor: whether they hold a bearer.
	 *
	 * Asked of the facts source, which memoises the bearer per user for the request,
	 * so asking it again is free and a page with no rules never asks it at all.
	 *
	 * @return bool
	 */
	private static function has_bearer(): bool {
		return '' !== Agend_Content_Access_Viewer_Facts::bearer();
	}

	/**
	 * TTL for the cached membership and segment facts.
	 *
	 * Short by intent. Membership standing decides what a visitor can see, so a
	 * long TTL means a lapsed member keeps their access for that long. Uses
	 * Core's `crm_me_entitlements` setting when available so an operator tunes one
	 * setting: default 300 seconds from Core 1.48.0, 60 seconds on an older Core
	 * where the key is unregistered, and never above 900 whatever the setting says.
	 * The rule lives on the facts source; this stays as the stable entry point
	 * the segment cache and the vocabulary cache already call, so the same ceiling
	 * applies to them.
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
	 * lapse takes effect without waiting out the TTL. Invalidates the membership
	 * entries and the segment entries for that user under every bearer, without
	 * needing to know one.
	 *
	 * @param int $user_id WordPress user id.
	 */
	public static function flush_member_facts( int $user_id ): void {
		Agend_Content_Access_Viewer_Facts::flush( $user_id );
	}
}
