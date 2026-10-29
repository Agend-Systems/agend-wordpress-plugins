<?php
/**
 * The one evaluator: does this visitor satisfy these rules?
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Judges an `Agend_Content_Access_Rules` set for the current visitor.
 *
 * Every decision this plugin makes goes through `verdict()`: the page gate and its
 * teaser path, the loop, excerpt and feed gates, the REST filter, a block, an
 * Elementor section, and the fallback a hidden block is replaced with. There used
 * to be two engines, a policy evaluator (`Decision::evaluate()`) and the display
 * condition engine, and an author could not tell which one a setting reached. A
 * second engine is also a second place for a fail-open mistake.
 *
 * It runs the existing condition engine (`Agend_Content_Access_Conditions`) over
 * the existing provider registry (`Agend_Content_Access_Condition_Providers`, bound
 * to live facts by `Agend_Content_Access_Condition_Runtime`). Every fact about the
 * visitor comes from `Agend_Content_Access_Viewer_Facts`, so a request makes at most
 * one `GET /v1/crm/me/entitlements` and one `GET /v1/crm/me/segments` call however
 * many things on the page carry rules.
 *
 * WHO THE VISITOR IS. An Agend identity is a bearer token, never a WordPress login:
 * a visitor with no bearer is anonymous, definitively and with no gateway call, and
 * one with a bearer is whoever Agend says. A WordPress login decides only the
 * `agend_wordpress` rules. No access decision reads WordPress user metadata or
 * WooCommerce, and a role is consulted only when an author wrote a role rule.
 *
 * EVERYTHING UNCERTAIN DENIES. An unreadable rule set, an unknown provider, a rule
 * that is malformed, a lookup that failed, a provider that threw, and a reversed
 * rule over any of those: all deny. The reversal matters. A lookup that could not
 * tell is neither "yes" nor "no", and negating "I could not tell" into "yes" is how
 * an outage discloses content, so the condition engine denies on unknown whatever
 * the reverse says and this keeps that.
 *
 * THE OPTIONS.
 *
 *   anonymous  bool  Judge the rules for somebody who is nobody. A syndication feed
 *                    is fetched without a session and its response is shared and
 *                    cached, so it must read the same for everyone. Nothing is
 *                    looked up in this mode: no bearer, no gateway call, and the
 *                    filter that supplies segments for the current visitor is not
 *                    consulted.
 *
 * THE STATE OF A DENIAL. Only the words shown to the person depend on it, never
 * whether they are admitted. The first row that applies wins:
 *
 *   | Visitor and rules                                              | state                   |
 *   | -------------------------------------------------------------- | ----------------------- |
 *   | allowed                                                        | granted                 |
 *   | anonymous, or signed out                                       | authentication_required |
 *   | signed in, holds nothing, a membership or plan rule involved   | membership_required     |
 *   | a member, a plan rule involved                                 | plan_required           |
 *   | anything else: segments, entitlements, roles, a reversed rule  | access_restricted       |
 *
 * "Signed out" is relative to the rules: when one of them is an Agend rule
 * (membership, a plan, a segment, an entitlement) it means "Agend does not know this
 * visitor", which includes a WordPress user with no linked member session and a
 * lookup Agend could not answer, because signing in to Agend is what would help. When
 * none is, it means "not logged in to WordPress". "Involved" counts only rules that
 * are not reversed: a member who fails a reversed `is_member` rule has nothing to join.
 * An unreadable rule set counts as involving a plan, as the unsatisfiable plan
 * policy it replaces did.
 */
class Agend_Content_Access_Access {

	/**
	 * Judges a rule set for the current visitor.
	 *
	 * @param Agend_Content_Access_Rules $rules   The rules.
	 * @param array                      $options See the class docblock. `anonymous` (bool).
	 * @return array{allowed: bool, state: string, degraded: bool, unknown: string[]}
	 *         `state` is one of the `Agend_Content_Access_Decision::STATE_*` constants.
	 *         `degraded` is true when a denial is down to something that could not be
	 *         evaluated rather than to the visitor, so an operator can tell an outage
	 *         from a lapsed membership. `unknown` names the rules that could not be.
	 */
	public static function verdict( Agend_Content_Access_Rules $rules, array $options = array() ): array {
		$anonymous = ! empty( $options['anonymous'] );

		// No rules is the common case and must cost nothing: no bearer, no lookup.
		if ( $rules->is_empty() ) {
			return self::result( true, Agend_Content_Access_Decision::STATE_GRANTED, false, array() );
		}

		try {
			if ( $rules->is_unreadable() ) {
				return self::result( false, self::denial_state( self::involved( array(), true ), $anonymous ), false, array() );
			}

			$checker = $anonymous
				? Agend_Content_Access_Condition_Runtime::anonymous_checker()
				: Agend_Content_Access_Condition_Runtime::checker();

			$allowed = true;
			$unknown = array();
			$failed  = array();

			// Every part is evaluated, even once one has denied. The facts are held for
			// the request, so it costs no more calls, and a broken rule behind a part
			// that denies is still reported to an administrator.
			foreach ( $rules->leaves() as $leaf ) {
				$result = Agend_Content_Access_Conditions::evaluate(
					$leaf->rules(),
					$checker,
					$leaf->match(),
					$leaf->is_inverted()
				);

				if ( ! $result['passes'] ) {
					$allowed  = false;
					$failed[] = array(
						'inverted' => $leaf->is_inverted(),
						'rules'    => self::rules_that_failed( $leaf, $result['evaluated'] ),
					);
				}

				$unknown = array_merge( $unknown, $result['unknown'] );
			}

			$unknown = array_values( array_unique( $unknown ) );

			if ( array() !== $unknown ) {
				// Loud in the log, silent to the visitor. An administrator needs to know
				// a rule is broken; a visitor must not learn which providers a site uses.
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf(
						'[Agend Content Access] Denied: access rules that could not be evaluated (%s). A provider plugin may be inactive, or Agend could not be asked.',
						implode( ', ', $unknown )
					)
				);

				// The engine denies on unknown already. Said again here so this does not
				// depend on it.
				$allowed = false;
			}

			if ( $allowed ) {
				return self::result( true, Agend_Content_Access_Decision::STATE_GRANTED, false, array() );
			}

			// What the denial is about is what the parts that denied involve: a member
			// who holds the plan the page names and fails a segment on the block is not
			// told to change plan.
			return self::result( false, self::denial_state( self::involved( $failed, false ), $anonymous ), array() !== $unknown, $unknown );
		} catch ( \Throwable $error ) {
			// A decision that could not be made is a denial. The log names the failure
			// for an administrator and nothing reaches the visitor.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				'[Agend Content Access] Denied: an access check failed (' . get_class( $error ) . ').'
			);

			return self::result(
				false,
				Agend_Content_Access_Decision::STATE_RESTRICTED,
				true,
				array( '(access check failed)' )
			);
		}
	}

	/**
	 * Whether a rule set is one a visitor could satisfy by joining or changing plan.
	 *
	 * True when any part holds a membership or plan rule that is not reversed, which
	 * is `involved()` read over every part and not only the parts that denied. The
	 * placeholder asks it to decide whether a signed-out visitor is also offered
	 * "Join": it has no visitor to ask, only the page's rules, and a visitor who is
	 * not signed in cannot be told which part they would have failed. It is only
	 * copy. It never admits anyone, so being wrong costs a button, not a body.
	 *
	 * @param Agend_Content_Access_Rules $rules The rules.
	 * @return bool
	 */
	public static function offers_membership( Agend_Content_Access_Rules $rules ): bool {
		$parts = array();

		foreach ( $rules->leaves() as $leaf ) {
			$parts[] = array(
				'inverted' => $leaf->is_inverted(),
				'rules'    => $leaf->rules(),
			);
		}

		$involved = self::involved( $parts, $rules->is_unreadable() );

		return $involved['membership'] || $involved['plan'];
	}

	/**
	 * Builds the result array.
	 *
	 * @param bool     $allowed  Whether the visitor may see it.
	 * @param string   $state    A `Decision::STATE_*` constant.
	 * @param bool     $degraded Whether the denial is down to something that could not be evaluated.
	 * @param string[] $unknown  The rules that could not be evaluated.
	 * @return array{allowed: bool, state: string, degraded: bool, unknown: string[]}
	 */
	private static function result( bool $allowed, string $state, bool $degraded, array $unknown ): array {
		return array(
			'allowed'  => $allowed,
			'state'    => $state,
			'degraded' => $degraded,
			'unknown'  => $unknown,
		);
	}

	/**
	 * The rules of a part that denied which are what denied it.
	 *
	 * For "any of" they are all of them. For "all of" they are the ones that were false, so
	 * a member who holds the plan a part names and fails a segment beside it is not told to
	 * change plan. A reversed part keeps all its rules: it is the whole that failed, and what
	 * matters about them is whose identity they ask for. A rule that could not be evaluated
	 * counts as false, and a part the engine refused outright (a malformed rule) keeps all.
	 *
	 * @param Agend_Content_Access_Rules $leaf      The part that denied.
	 * @param array<string, bool>        $evaluated What the engine made of each rule.
	 * @return string[]
	 */
	private static function rules_that_failed( Agend_Content_Access_Rules $leaf, array $evaluated ): array {
		if ( $leaf->is_inverted() ) {
			return $leaf->rules();
		}

		$false = array();

		foreach ( $evaluated as $rule => $result ) {
			if ( false === $result ) {
				$false[] = (string) $rule;
			}
		}

		return array() === $false ? $leaf->rules() : $false;
	}

	/**
	 * What the parts that denied involve, from the rules alone: no visitor and no lookup.
	 *
	 * `agend` is set when any rule is answered by Agend, reversed or not, because that
	 * decides whose identity is asked. `membership` and `plan` are set only by rules
	 * that are not reversed, since only those can send a visitor to join or upgrade. A
	 * legacy ESAC provider name counts as the provider it is an alias of. A rule set
	 * that cannot be read is treated as naming a plan: it is only copy, and it never
	 * admits anyone.
	 *
	 * @param array<int, array{inverted: bool, rules: string[]}> $parts      The parts that denied.
	 * @param bool                                               $unreadable Whether the rules could not be read.
	 * @return array{agend: bool, membership: bool, plan: bool}
	 */
	private static function involved( array $parts, bool $unreadable ): array {
		$involved = array(
			'agend'      => $unreadable,
			'membership' => $unreadable,
			'plan'       => $unreadable,
		);

		foreach ( $parts as $part ) {
			foreach ( $part['rules'] as $rule ) {
				$at = strpos( $rule, ':' );

				if ( false === $at || 0 === $at ) {
					continue;
				}

				$provider = Agend_Content_Access_Condition_Providers::canonical( substr( $rule, 0, $at ) );

				if ( null === $provider || Agend_Content_Access_Condition_Providers::WORDPRESS === $provider ) {
					continue;
				}

				$involved['agend'] = true;

				if ( $part['inverted'] || Agend_Content_Access_Condition_Providers::MEMBERSHIP !== $provider ) {
					continue;
				}

				if ( 'is_member' === substr( $rule, $at + 1 ) ) {
					$involved['membership'] = true;
				} else {
					$involved['plan'] = true;
				}
			}
		}

		return $involved;
	}

	/**
	 * The state of a denial: see the class docblock.
	 *
	 * Only asks Agend about the visitor when an Agend rule is involved, so a page
	 * whose rules are all WordPress rules never costs a gateway call to be refused.
	 *
	 * @param array{agend: bool, membership: bool, plan: bool} $involved  From `involved()`.
	 * @param bool                                             $anonymous Whether the visitor is nobody.
	 * @return string One of the `Decision::STATE_*` constants.
	 */
	private static function denial_state( array $involved, bool $anonymous ): string {
		if ( $anonymous ) {
			return Agend_Content_Access_Decision::STATE_AUTH;
		}

		if ( ! $involved['agend'] ) {
			return is_user_logged_in()
				? Agend_Content_Access_Decision::STATE_RESTRICTED
				: Agend_Content_Access_Decision::STATE_AUTH;
		}

		// Agend knows the visitor only through a bearer, and only when it can say what
		// they hold. Anything else is "sign in".
		$facts = '' === Agend_Content_Access_Viewer_Facts::bearer() ? null : Agend_Content_Access_Condition_Runtime::member_facts();

		if ( null === $facts ) {
			return Agend_Content_Access_Decision::STATE_AUTH;
		}

		if ( $involved['membership'] || $involved['plan'] ) {
			// Holding nothing at all is a membership problem even when the rules name
			// specific plans. A member, to the evaluator, is the runtime's definition.
			$holds_nothing = empty( $facts['is_member'] ) && array() === $facts['tier_ids'];

			if ( $holds_nothing ) {
				return Agend_Content_Access_Decision::STATE_MEMBERSHIP;
			}

			if ( $involved['plan'] ) {
				return Agend_Content_Access_Decision::STATE_PLAN;
			}
		}

		return Agend_Content_Access_Decision::STATE_RESTRICTED;
	}
}
