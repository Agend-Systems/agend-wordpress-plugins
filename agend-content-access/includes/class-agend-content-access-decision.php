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
 * the cache lifetime (Core's `crm_me_entitlements` setting, five minutes by
 * default). The reason is cost and consistency: a page with a document policy
 * and conditioned sections asked Agend twice under two different lifetimes, and
 * every protected page view paid a gateway round trip. The price is that a
 * revocation which fires no webhook takes up to that lifetime to apply.
 * Webhook invalidation arrives in a later layer and can only make this forget
 * sooner, never remember longer, so the TTL remains the backstop. A failed
 * lookup is never cached.
 */
class Agend_Content_Access_Decision {

	const STATE_GRANTED    = 'granted';
	const STATE_AUTH       = 'authentication_required';
	const STATE_MEMBERSHIP = 'membership_required';
	const STATE_PLAN       = 'plan_required';

	/**
	 * Per-request memo of the resolved viewer, keyed by nothing: the viewer does
	 * not change mid-request.
	 *
	 * This memoises the DERIVED answer (identified, tiers, degraded), not the
	 * gateway read, which `Agend_Content_Access_Viewer_Facts` memoises and
	 * caches. It is kept so the bearer lookup below, which can read user meta
	 * and refresh a token, runs once per request rather than once per element.
	 *
	 * @var array|null
	 */
	private static ?array $viewer_cache = null;

	/**
	 * Resets the memos, this class's and the member facts source's. Tests only.
	 */
	public static function reset_cache(): void {
		self::$viewer_cache = null;

		// Guarded because the facts source is a separate file. A caller that has
		// loaded this class without it has nothing there to reset.
		if ( class_exists( 'Agend_Content_Access_Viewer_Facts', false ) ) {
			Agend_Content_Access_Viewer_Facts::reset();
		}
	}

	/**
	 * Resolves the current visitor's standing from Agend.
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
	 * @return array{identified: bool, tier_ids: string[], degraded: bool}
	 */
	public static function viewer(): array {
		if ( null !== self::$viewer_cache ) {
			return self::$viewer_cache;
		}

		$viewer = array(
			'identified' => false,
			'tier_ids'   => array(),
			'degraded'   => false,
		);

		$bearer = function_exists( 'agend_apps_get_bearer_token' )
			? agend_apps_get_bearer_token()
			: '';

		if ( '' === $bearer ) {
			self::$viewer_cache = $viewer;
			return $viewer;
		}

		if ( ! function_exists( 'agend_apps_crm_get_my_entitlements' ) ) {
			// Agend Apps Core predates the resolved-standing wrapper. The
			// visitor holds a session we cannot evaluate, so they are denied
			// and the situation is flagged rather than guessed at. Deliberately
			// NOT a hard dependency in the gate: an older Core still supports
			// document policies for anonymous visitors, and refusing to load
			// would turn a partial capability into no protection at all.
			$viewer['degraded'] = true;
			self::$viewer_cache = $viewer;
			return $viewer;
		}

		$facts = Agend_Content_Access_Viewer_Facts::member();

		if ( null === $facts ) {
			// Agend could not answer. The visitor holds a session, so they are
			// not anonymous, but we cannot claim they hold anything either.
			// `degraded` lets the caller log an operational error rather than
			// silently treating an outage as a lapsed membership.
			$viewer['degraded']  = true;
			self::$viewer_cache  = $viewer;
			return $viewer;
		}

		$viewer['identified'] = true;
		$viewer['tier_ids']   = $facts['tier_ids'];

		self::$viewer_cache = $viewer;

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
	 * @param array|null $policy Canonical policy, or null when none is set.
	 * @param array      $viewer Result of `viewer()`.
	 * @return string One of the STATE_* constants.
	 */
	public static function evaluate( ?array $policy, array $viewer ): string {
		// No policy is not a restriction. Decision 2.9: a record with no policy
		// predates the capability being enabled and stays public.
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

		// An unrecognised mode is a policy we cannot honour. Deny rather than
		// guess: an unreadable restriction is not evidence of no restriction.
		return self::STATE_MEMBERSHIP;
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
	 * The full decision for one post.
	 *
	 * @param int $post_id Post id.
	 * @return array{state: string, policy: array|null, preview: bool, degraded: bool}
	 */
	public static function for_post( int $post_id ): array {
		$policy = Agend_Content_Access_Policy::get_for_post( $post_id );

		if ( null === $policy || Agend_Content_Access_Policy::MODE_PUBLIC === ( $policy['mode'] ?? '' ) ) {
			// Public and unset documents short-circuit BEFORE any identity
			// lookup, so an unauthenticated visitor to a public page never
			// triggers a gateway call.
			return array(
				'state'    => self::STATE_GRANTED,
				'policy'   => $policy,
				'preview'  => false,
				'degraded' => false,
			);
		}

		if ( self::can_preview( $post_id ) ) {
			return array(
				'state'    => self::STATE_GRANTED,
				'policy'   => $policy,
				'preview'  => true,
				'degraded' => false,
			);
		}

		$viewer = self::viewer();

		return array(
			'state'    => self::evaluate( $policy, $viewer ),
			'policy'   => $policy,
			'preview'  => false,
			'degraded' => ! empty( $viewer['degraded'] ),
		);
	}
}
