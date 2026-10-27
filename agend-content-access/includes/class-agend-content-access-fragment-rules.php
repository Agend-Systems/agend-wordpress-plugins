<?php
/**
 * Editor-neutral rules for a fragment: display conditions and replacement content.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates the display conditions and the fallback a fragment carries,
 * whichever editor the fragment came from.
 *
 * These were methods on the Elementor class that never touched Elementor: they
 * read a flat settings array and ask the condition engine and the decision
 * engine. They live here so a second editor driver (the block editor) reuses
 * the same evaluation instead of growing a second copy that could drift.
 *
 * The settings array is the one `Agend_Content_Access_Policy` documents. The
 * audience half is read by `Policy::from_fragment_settings()`; the display
 * condition half is keyed by the constants below. Values stay Elementor-shaped,
 * toggles being the string `'yes'`, because that is what every stored document
 * already holds and a new editor converts into it.
 *
 * Display conditions are kept apart from the access POLICY, and the reason is
 * worth restating because the two look alike. The policy travels to Agend and
 * drives the server-side projection: it is a security boundary, enforced
 * whether or not WordPress renders correctly. Display conditions are evaluated
 * HERE, in the theme, and personalise what a page shows. Merging them would
 * invite an editor to treat a local render decision as protection. They compose
 * instead: either can hide a fragment, neither can reveal one the other hid.
 */
class Agend_Content_Access_Fragment_Rules {

	/**
	 * Display-condition settings keys (US-6.1, ESAC parity).
	 *
	 * @var string
	 */
	const ENABLED_KEY    = 'agend_conditions_enabled';
	const CONDITIONS_KEY = 'agend_conditions';
	const MATCH_KEY      = 'agend_conditions_match';
	const INVERT_KEY     = 'agend_conditions_invert';
	const FALLBACK_KEY   = 'agend_conditions_fallback_id';

	/**
	 * Whether a fragment has display conditions switched on.
	 *
	 * Kept in one place so the toggle's `'yes'` value is not re-spelled by every
	 * caller. Having conditions on, even with none chosen, is what takes an
	 * element out of a shared cache, so the cache check and the evaluation below
	 * must agree on it.
	 *
	 * @param array $settings Fragment settings.
	 * @return bool
	 */
	public static function conditions_enabled( array $settings ): bool {
		return ! empty( $settings[ self::ENABLED_KEY ] )
			&& 'yes' === $settings[ self::ENABLED_KEY ];
	}

	/**
	 * Do this fragment's display conditions admit the current visitor?
	 *
	 * Returns true when conditions are switched off or none are chosen, which
	 * is the overwhelmingly common case and must cost nothing.
	 *
	 * An unknown provider or a malformed condition DENIES, and the reason is
	 * logged for an administrator rather than surfaced to the visitor. The
	 * reference implementation passes in both cases, which turns a deactivated
	 * plugin into a silent disclosure.
	 *
	 * @param array         $settings Fragment settings.
	 * @param callable|null $checker  Condition checker, or null for the live visitor's
	 *                                (`Agend_Content_Access_Condition_Runtime::checker()`).
	 *                                A feed passes the anonymous one.
	 * @return bool
	 */
	public static function conditions_pass( array $settings, ?callable $checker = null ): bool {
		if ( ! self::conditions_enabled( $settings ) ) {
			return true;
		}

		$conditions = isset( $settings[ self::CONDITIONS_KEY ] ) && is_array( $settings[ self::CONDITIONS_KEY ] )
			? $settings[ self::CONDITIONS_KEY ]
			: array();

		$match = ( isset( $settings[ self::MATCH_KEY ] )
			&& Agend_Content_Access_Conditions::MATCH_ALL === $settings[ self::MATCH_KEY ] )
			? Agend_Content_Access_Conditions::MATCH_ALL
			: Agend_Content_Access_Conditions::MATCH_ANY;

		$invert = ! empty( $settings[ self::INVERT_KEY ] )
			&& 'yes' === $settings[ self::INVERT_KEY ];

		$result = Agend_Content_Access_Conditions::evaluate(
			$conditions,
			null === $checker ? Agend_Content_Access_Condition_Runtime::checker() : $checker,
			$match,
			$invert
		);

		if ( array() !== $result['unknown'] ) {
			// Loud in the log, silent to the visitor. An administrator needs to
			// know a rule is broken; a visitor must not learn which providers a
			// site uses.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[Agend Content Access] Denied an element: unrecognised display conditions (%s). A provider plugin may be inactive.',
					implode( ', ', $result['unknown'] )
				)
			);
		}

		return (bool) $result['passes'];
	}

	/**
	 * The rendered fallback, or an empty string when it may not be shown.
	 *
	 * THE FALLBACK IS ITSELF ACCESS-CHECKED. This is the one place the
	 * reference implementation gets dangerously wrong: it renders the chosen
	 * post's content unconditionally. An editor who picks a members-only page
	 * as the fallback for a members-only section therefore discloses it to
	 * precisely the people who just failed the check, and the mistake looks
	 * like a thoughtful "here is what you are missing" until somebody notices.
	 *
	 * Here the fallback is only rendered when the CURRENT visitor could have
	 * viewed it on its own. The picker offers public content only, and this is
	 * the enforcement behind that: a page restricted after being chosen is
	 * silently dropped rather than leaked.
	 *
	 * A password-protected post is never shown. Its password is checked where the
	 * post is rendered as a post, and a fallback is rendered here, straight from
	 * `post_content`, by a different route that never asks for it. Showing it would
	 * hand the body to every visitor the conditions refused, with no password
	 * prompt, and the picker is not a defence: a password can be added to a post
	 * after it was chosen, just as a policy can.
	 *
	 * @param int  $fallback_id Post id chosen as the fallback.
	 * @param bool $anonymous   Judge the fallback for a visitor who is nobody, as a
	 *                          feed must: its output is shared whoever fetched it.
	 * @return string
	 */
	public static function fallback_html( int $fallback_id, bool $anonymous = false ): string {
		$post = get_post( $fallback_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return '';
		}

		if ( '' !== (string) $post->post_password ) {
			return '';
		}

		// The access check the reference omits. `evaluate` returns the state
		// for THIS visitor against the fallback's own policy, and the viewer is
		// built for that policy so segments are fetched only when it needs them.
		// Enforced reading: a fallback whose stored policy is unreadable is not
		// shown.
		$policy = Agend_Content_Access_Policy::enforced_for_post( $fallback_id );
		$state  = Agend_Content_Access_Decision::evaluate(
			$policy,
			$anonymous ? Agend_Content_Access_Decision::anonymous_viewer() : Agend_Content_Access_Decision::viewer( $policy )
		);

		if ( Agend_Content_Access_Decision::STATE_GRANTED !== $state ) {
			return '';
		}

		return trim( (string) apply_filters( 'the_content', $post->post_content ) );
	}
}
