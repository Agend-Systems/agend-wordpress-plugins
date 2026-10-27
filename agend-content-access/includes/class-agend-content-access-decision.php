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
 * Decides whether the current visitor may read a document, and holds the
 * decision states every gate speaks.
 *
 * The division of labour, which is the whole architecture in one sentence:
 * AGEND says who the visitor is and what they hold; WordPress says who a thing is
 * for. This class asks the one evaluator, `Agend_Content_Access_Access`, whether
 * the visitor satisfies the page's rules. The member definition itself is never
 * computed in PHP: it arrives already resolved from `GET /v1/crm/me/entitlements`
 * (Decision 2.5).
 *
 * It used to hold a second engine, a policy evaluator and its own resolved-viewer
 * memo, beside the display condition engine. Both are gone: a page's stored policy
 * is read into the same rule list a block's and a section's are
 * (`Agend_Content_Access_Rules::from_post()`), and one evaluator judges them all,
 * so a fail-open mistake has one place to hide instead of two. What is left is the
 * page-level decision (`for_post()`), which adds the two things only a page has:
 * the authoring preview, and the anonymous reader a feed is.
 *
 * Fail closed at every step. An unreachable Agend, an unparseable response, a
 * bearer that cannot be obtained: all deny. The only inputs that can GRANT are a
 * post with no rules, a WordPress edit capability, or rules the visitor actually
 * satisfies.
 *
 * THE LOOKUP IS CACHED, deliberately. It comes from
 * `Agend_Content_Access_Viewer_Facts`, the one source shared by every rule:
 * memoised for the request and held per member in a transient for the cache
 * lifetime (Core's `crm_me_entitlements` setting: 300 seconds by default from Core
 * 1.48.0, 60 on an older Core where the key is unregistered, and never more than
 * 900 whatever it is set to). The reason is cost and consistency: every protected
 * page view would otherwise pay a gateway round trip, and a page with a page rule
 * and ruled blocks would ask twice under two different lifetimes. The price is that
 * a revocation which fires no webhook takes up to that lifetime to apply. Webhook
 * invalidation can only make this forget sooner, never remember longer, so the TTL
 * remains the backstop. A failed lookup is never cached.
 *
 * SEGMENTS ARE LOADED ON DEMAND. Entitlement keys arrive free with the member's
 * standing, from the same `/crm/me/entitlements` response. Segments do not: they
 * are a second gateway call, and almost no page names one. The evaluator asks for
 * them only when a rule being evaluated names a segment, and once per request.
 */
class Agend_Content_Access_Decision {

	const STATE_GRANTED    = 'granted';
	const STATE_AUTH       = 'authentication_required';
	const STATE_MEMBERSHIP = 'membership_required';
	const STATE_PLAN       = 'plan_required';

	/**
	 * The generic denial: segments, entitlements, roles and reversed rules.
	 *
	 * Adopted from the gateway's state vocabulary (`access_restricted`). A
	 * segment is an arbitrary audience and an entitlement can be held with no
	 * membership, so an identified visitor outside either is neither a
	 * "membership" nor a "plan" problem. Telling them to join, or to change plan,
	 * would mislabel it. `Agend_Content_Access_Access` documents which denial gets
	 * which state.
	 */
	const STATE_RESTRICTED = 'access_restricted';

	/**
	 * Resets the per-request memos. Tests only.
	 *
	 * The memo is the facts source's: this class keeps none of its own.
	 */
	public static function reset_cache(): void {
		// Guarded because the facts source is a separate file. A caller that has
		// loaded this class without it has nothing there to reset.
		if ( class_exists( 'Agend_Content_Access_Viewer_Facts', false ) ) {
			Agend_Content_Access_Viewer_Facts::reset();
		}
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
