<?php
/**
 * Access decision for a WordPress-rendered document.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether the current visitor may read a policy-bearing document.
 *
 * The division of labour, which is the whole architecture in one sentence:
 * AGEND says who the visitor is and what they hold; this class compares that
 * against the policy WordPress stored. The comparison is deterministic
 * arithmetic over a tier set, so doing it here is not a second opinion about
 * membership. The member definition itself is never computed in PHP: it arrives
 * already resolved from `GET /v1/crm/me/entitlements` (Decision 2.5).
 *
 * Fail closed at every step. An unreachable Agend, an unparseable response, a
 * bearer that cannot be obtained: all deny. The only inputs that can GRANT are
 * an explicit public policy, a WordPress edit capability, or a tier set that
 * actually satisfies the policy.
 *
 * THE LOOKUP IS CACHED, deliberately. It used to run once per request and be
 * stored nowhere, so a revocation applied on the next page load. It now comes
 * from `Agend_Content_Access_Viewer_Facts`, the one source shared with display
 * conditions: memoised for the request and held per member in a transient for
 * the cache lifetime (Core's `crm_me_entitlements` setting: 300 seconds by
 * default from Core 1.48.0, 60 on an older Core where the key is unregistered,
 * and never more than 900 whatever it is set to). The reason is cost and
 * consistency: a page with a document policy and conditioned sections asked
 * Agend twice under two different lifetimes, and every protected page view paid
 * a gateway round trip. The price is that a revocation which fires no webhook
 * takes up to that lifetime to apply. Webhook invalidation arrives in a later
 * layer and can only make this forget sooner, never remember longer, so the TTL
 * remains the backstop. A failed lookup is never cached.
 *
 * SEGMENTS ARE LOADED ON DEMAND, AND THE POLICY SAYS WHEN. Entitlement keys
 * arrive free with the member's standing, from the same
 * `/crm/me/entitlements` response. Segments do not: they are a second gateway
 * call, and almost no page carries a segments policy. So `evaluate()` stays a
 * pure function of a policy and a viewer array, and `viewer()` takes the policy
 * about to be evaluated and fetches segments only when `needs_segments()` says
 * that policy cannot be decided without them. The alternative, a viewer that
 * carries a lazy resolver, would hide a network call inside what reads as plain
 * data and would make a viewer array stop being comparable, serialisable or
 * easy to build in a test. The cost of the chosen design is that a caller must
 * hand `viewer()` the policy it is about to evaluate. One that forgets gets a
 * viewer with no segments, which a segments policy reads as "in none": a
 * denial, never a grant. That is the same defaulting-empty contract the
 * gateway's `ViewerEntitlement.segmentIds` has.
 */
class Agend_Content_Access_Decision {

	const STATE_GRANTED    = 'granted';
	const STATE_AUTH       = 'authentication_required';
	const STATE_MEMBERSHIP = 'membership_required';
	const STATE_PLAN       = 'plan_required';

	/**
	 * The generic denial for the segments and selected-entitlements policies.
	 *
	 * Adopted from the gateway's state vocabulary (`access_restricted`). A
	 * segment is an arbitrary audience and an entitlement can be held with no
	 * membership, so an identified visitor outside either is neither a
	 * "membership" nor a "plan" problem. Telling them to join, or to change plan,
	 * would mislabel it.
	 */
	const STATE_RESTRICTED = 'access_restricted';

	/**
	 * Per-request memo of the resolved base viewer, keyed by WordPress user id and
	 * a digest of the bearer: `"<user id>:<digest>"`, the digest empty for no
	 * bearer.
	 *
	 * This memoises the DERIVED answer (identified, tiers, entitlements,
	 * degraded), not the gateway read, which `Agend_Content_Access_Viewer_Facts`
	 * memoises and caches. It is kept so the derivation runs once per request
	 * rather than once per element. It is keyed by who is asking, as the facts
	 * layer is, because the viewer is only constant while the visitor is: a request
	 * that switches user (a login completing, an impersonation that restores
	 * afterwards) or whose bearer changes must not be answered with the previous
	 * visitor's standing, which a single unkeyed value would do.
	 *
	 * It never holds segments: those are added to a COPY by `viewer()` when a
	 * policy asks, so one element's segments policy cannot make every later
	 * element's viewer look as though segments were loaded.
	 *
	 * @var array<string, array>
	 */
	private static array $viewer_cache = array();

	/**
	 * Resets the memos, this class's and the member facts source's. Tests only.
	 */
	public static function reset_cache(): void {
		self::$viewer_cache = array();

		// Guarded because the facts source is a separate file. A caller that has
		// loaded this class without it has nothing there to reset.
		if ( class_exists( 'Agend_Content_Access_Viewer_Facts', false ) ) {
			Agend_Content_Access_Viewer_Facts::reset();
		}
	}

	/**
	 * Resolves the current visitor's standing from Agend, for one policy.
	 *
	 * `identified` means Agend accepted a bearer for this visitor, NOT that
	 * WordPress has them logged in. An ordinary WordPress account with no linked
	 * Agend member session is anonymous here (US-3.4 criterion 4), because a
	 * local login says nothing about membership.
	 *
	 * A visitor whose session cannot produce a bearer, or whose lookup fails, is
	 * likewise unidentified with no tiers (criterion 9). That resolves to
	 * "authentication required" rather than to a local-metadata fallback, which
	 * would be exactly the second entitlement authority this design forbids.
	 *
	 * Pass the policy about to be evaluated. When it is a segments policy with
	 * something to match, and the visitor is identified, their segments are
	 * fetched (once per request, shared with display conditions) and returned as
	 * `segment_ids`. For any other policy no segment call is made and
	 * `segment_ids` is empty. If the segment lookup fails the visitor stays
	 * identified but the viewer is flagged `degraded`, and `segment_ids` stays
	 * empty so the policy denies: an outage must not read as "in no segments"
	 * silently.
	 *
	 * @param array|null $policy The policy about to be evaluated, or null when no segments are needed.
	 * @return array{
	 *     identified: bool,
	 *     tier_ids: string[],
	 *     entitlement_keys: string[],
	 *     segment_ids: string[],
	 *     degraded: bool
	 * }
	 */
	public static function viewer( ?array $policy = null ): array {
		$viewer = self::base_viewer();

		if ( ! $viewer['identified'] || ! Agend_Content_Access_Policy::needs_segments( $policy ) ) {
			return $viewer;
		}

		$segments = Agend_Content_Access_Viewer_Facts::segments();

		if ( null === $segments ) {
			$viewer['degraded'] = true;

			return $viewer;
		}

		$viewer['segment_ids'] = $segments['segment_ids'];

		return $viewer;
	}

	/**
	 * The visitor's standing without segments, resolved once per request.
	 *
	 * @return array{identified: bool, tier_ids: string[], entitlement_keys: string[], segment_ids: string[], degraded: bool}
	 */
	private static function base_viewer(): array {
		// The facts source's bearer, memoised per user per request, so the viewer
		// and the facts it reads are bound to the same identity.
		$bearer   = Agend_Content_Access_Viewer_Facts::bearer();
		$memo_key = (int) get_current_user_id() . ':' . Agend_Content_Access_Viewer_Facts::bearer_digest( $bearer );

		if ( isset( self::$viewer_cache[ $memo_key ] ) ) {
			return self::$viewer_cache[ $memo_key ];
		}

		$viewer = array(
			'identified'       => false,
			'tier_ids'         => array(),
			'entitlement_keys' => array(),
			'segment_ids'      => array(),
			'degraded'         => false,
		);

		if ( '' === $bearer ) {
			self::$viewer_cache[ $memo_key ] = $viewer;
			return $viewer;
		}

		if ( ! function_exists( 'agend_apps_crm_get_my_entitlements' ) ) {
			// Agend Apps Core predates the resolved-standing wrapper. The
			// visitor holds a session we cannot evaluate, so they are denied
			// and the situation is flagged rather than guessed at. Deliberately
			// NOT a hard dependency in the gate: an older Core still supports
			// document policies for anonymous visitors, and refusing to load
			// would turn a partial capability into no protection at all.
			$viewer['degraded']              = true;
			self::$viewer_cache[ $memo_key ] = $viewer;
			return $viewer;
		}

		$facts = Agend_Content_Access_Viewer_Facts::member();

		if ( null === $facts ) {
			// Agend could not answer. The visitor holds a session, so they are
			// not anonymous, but we cannot claim they hold anything either.
			// `degraded` lets the caller log an operational error rather than
			// silently treating an outage as a lapsed membership.
			$viewer['degraded']              = true;
			self::$viewer_cache[ $memo_key ] = $viewer;
			return $viewer;
		}

		$viewer['identified']       = true;
		$viewer['tier_ids']         = $facts['tier_ids'];
		$viewer['entitlement_keys'] = $facts['entitlement_keys'];

		self::$viewer_cache[ $memo_key ] = $viewer;

		return $viewer;
	}

	/**
	 * Evaluates a policy against a resolved viewer.
	 *
	 * Pure: no network, no globals. The state vocabulary matches the CMS
	 * projection's `ContentAccessResult` so a WordPress-rendered page and an
	 * API-projected one describe the same situation the same way.
	 *
	 * `membership_required` and `plan_required` are kept distinct because they
	 * ask different things of the visitor. Telling a paying member to go and
	 * join is wrong; telling a non-member their plan is insufficient is
	 * confusing.
	 *
	 * The segments and selected-entitlements policies are granted when the
	 * viewer's set intersects the policy's. Outside it, an unidentified visitor
	 * is asked to sign in and an identified one is `access_restricted`, never
	 * `membership_required` or `plan_required`. This is the gateway's
	 * `resolveAccessState()`, arm for arm.
	 *
	 * A viewer array that omits `entitlement_keys` or `segment_ids` holds none,
	 * so a missing fact can only deny.
	 *
	 * @param array|null $policy Canonical policy, or null when none is set.
	 * @param array      $viewer Result of `viewer()`, called with this policy.
	 * @return string One of the STATE_* constants.
	 */
	public static function evaluate( ?array $policy, array $viewer ): string {
		// No policy is not a restriction. Decision 2.9: a record with no policy
		// predates the capability being enabled and stays public. Callers pass
		// null ONLY for a post with nothing stored; a stored policy that cannot
		// be read arrives as `Policy::UNSATISFIABLE`, an empty plan list, which
		// the plan branch below denies for everybody.
		if ( null === $policy ) {
			return self::STATE_GRANTED;
		}

		$mode = $policy['mode'] ?? '';

		if ( Agend_Content_Access_Policy::MODE_PUBLIC === $mode ) {
			return self::STATE_GRANTED;
		}

		$identified = ! empty( $viewer['identified'] );
		$tier_ids   = isset( $viewer['tier_ids'] ) && is_array( $viewer['tier_ids'] )
			? $viewer['tier_ids']
			: array();
		$holds_any  = array() !== $tier_ids;

		if ( Agend_Content_Access_Policy::MODE_MEMBERS === $mode ) {
			if ( $holds_any ) {
				return self::STATE_GRANTED;
			}

			return $identified ? self::STATE_MEMBERSHIP : self::STATE_AUTH;
		}

		if ( Agend_Content_Access_Policy::MODE_TIERS === $mode ) {
			$allowed = isset( $policy['tier_ids'] ) && is_array( $policy['tier_ids'] )
				? $policy['tier_ids']
				: array();

			if ( array() !== array_intersect( $tier_ids, $allowed ) ) {
				return self::STATE_GRANTED;
			}

			if ( ! $identified ) {
				return self::STATE_AUTH;
			}

			// Holding nothing at all is a membership problem, not a plan
			// problem, even though the policy names specific plans.
			return $holds_any ? self::STATE_PLAN : self::STATE_MEMBERSHIP;
		}

		if ( Agend_Content_Access_Policy::MODE_SEGMENTS === $mode ) {
			return self::set_state(
				self::strings( $viewer['segment_ids'] ?? null ),
				Agend_Content_Access_Policy::segment_ids( $policy ),
				$identified
			);
		}

		if ( Agend_Content_Access_Policy::MODE_ENTITLEMENTS === $mode ) {
			return self::set_state(
				self::strings( $viewer['entitlement_keys'] ?? null ),
				Agend_Content_Access_Policy::entitlement_keys( $policy ),
				$identified
			);
		}

		// An unrecognised mode is a policy we cannot honour. Deny rather than
		// guess: an unreadable restriction is not evidence of no restriction.
		return self::STATE_MEMBERSHIP;
	}

	/**
	 * The state for a policy that admits anyone holding at least one of a set.
	 *
	 * Shared by the segments and selected-entitlements arms, which differ only in
	 * which set the viewer is compared on. An empty allowed list is
	 * unsatisfiable by construction (a disjoint intersection), so it falls
	 * through to a denial rather than granting.
	 *
	 * @param string[] $held       What the viewer holds.
	 * @param string[] $allowed    What the policy names.
	 * @param bool     $identified Whether Agend accepted a bearer for the viewer.
	 * @return string One of the STATE_* constants.
	 */
	private static function set_state( array $held, array $allowed, bool $identified ): string {
		foreach ( $held as $item ) {
			if ( in_array( $item, $allowed, true ) ) {
				return self::STATE_GRANTED;
			}
		}

		return $identified ? self::STATE_RESTRICTED : self::STATE_AUTH;
	}

	/**
	 * The strings in a viewer field, as a list; anything else holds nothing.
	 *
	 * @param mixed $value Candidate list.
	 * @return string[]
	 */
	private static function strings( $value ): array {
		return is_array( $value )
			? array_values( array_filter( $value, 'is_string' ) )
			: array();
	}

	/**
	 * Whether the current WordPress user may preview regardless of membership.
	 *
	 * Editors and administrators see their own content (US-3.4 criterion 2).
	 * This is an AUTHORING capability, not a membership claim, and it is the one
	 * place a WordPress capability legitimately affects what is rendered.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function can_preview( int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * A viewer who is nobody: not identified, holding no plan, entitlement or
	 * segment. Resolves nothing, so it costs no gateway call and cannot degrade.
	 *
	 * Public so a fragment (a block) in a syndication feed is judged against the
	 * same nobody a feed's document is: see `for_post()`.
	 *
	 * @return array{identified: bool, tier_ids: string[], entitlement_keys: string[], segment_ids: string[], degraded: bool}
	 */
	public static function anonymous_viewer(): array {
		return array(
			'identified'       => false,
			'tier_ids'         => array(),
			'entitlement_keys' => array(),
			'segment_ids'      => array(),
			'degraded'         => false,
		);
	}

	/**
	 * The full decision for one post.
	 *
	 * The post's rules are `Agend_Content_Access_Rules::from_post()`: the new
	 * `_agend_access_rules`, or the 0.1.x `_agend_access_policy` converted. No rules
	 * is public. Rules that cannot be read deny everyone, so a stored value this
	 * version cannot parse never publishes a page.
	 *
	 * `$anonymous` evaluates the post for a reader who cannot be identified at all:
	 * no editor preview and no identity lookup, so a restricted post is denied
	 * outright. It exists for syndication feeds. A feed is fetched by aggregators
	 * that carry no session, and its response is routinely cached and shared, so a
	 * render personalised to whoever happened to ask would be served to everyone
	 * after them. It is judged against the same rules as any other reader, so an
	 * unreadable stored value denies here too, but it never reaches a bearer: no
	 * entitlements or segments are fetched, and the visitor's own session cannot
	 * change the answer.
	 *
	 * The return keeps the keys it has always had. `policy` is the rules as the
	 * `@agend/access-policy` arm that says them, for the gate copy that names plans
	 * and entitlements, and null when the post has no rules; the unsatisfiable
	 * policy stands for rules no arm can say. `rules` is the rules themselves, and
	 * is what every caller that decides anything reads.
	 *
	 * @param int  $post_id   Post id.
	 * @param bool $anonymous Evaluate as an anonymous reader.
	 * @return array{state: string, policy: array|null, rules: Agend_Content_Access_Rules, preview: bool, degraded: bool}
	 */
	public static function for_post( int $post_id, bool $anonymous = false ): array {
		$rules  = Agend_Content_Access_Rules::from_post( $post_id );
		$policy = $rules->is_empty() ? null : $rules->to_policy();

		if ( $rules->is_empty() ) {
			// Unset documents short-circuit BEFORE any identity lookup, so an
			// unauthenticated visitor to a public page never triggers a gateway call.
			return array(
				'state'    => self::STATE_GRANTED,
				'policy'   => $policy,
				'rules'    => $rules,
				'preview'  => false,
				'degraded' => false,
			);
		}

		if ( $anonymous ) {
			$verdict = Agend_Content_Access_Access::verdict( $rules, array( 'anonymous' => true ) );

			return array(
				'state'    => $verdict['state'],
				'policy'   => $policy,
				'rules'    => $rules,
				'preview'  => false,
				'degraded' => $verdict['degraded'],
			);
		}

		if ( self::can_preview( $post_id ) ) {
			return array(
				'state'    => self::STATE_GRANTED,
				'policy'   => $policy,
				'rules'    => $rules,
				'preview'  => true,
				'degraded' => false,
			);
		}

		$verdict = Agend_Content_Access_Access::verdict( $rules );

		return array(
			'state'    => $verdict['state'],
			'policy'   => $policy,
			'rules'    => $rules,
			'preview'  => false,
			'degraded' => $verdict['degraded'],
		);
	}
}
