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
 * The settings keys of the old display conditions, and the replacement content a
 * hidden fragment is shown in place of, whichever editor the fragment came from.
 *
 * This used to evaluate the display conditions as well. That is
 * `Agend_Content_Access_Access` now, for every kind of rule, and what is left is
 * what was never about evaluation: the Elementor-shaped keys that every stored
 * document already holds (toggles being the string `'yes'`, because that is what
 * the experiment wrote first and a new editor converts into it), and the one
 * access-checked render of a fallback.
 *
 * The settings array is the one `Agend_Content_Access_Policy` documents, and
 * `Agend_Content_Access_Rules::from_elementor_settings()` is its one reader: it
 * understands the old policy keys, these old condition keys and the new rule keys,
 * and turns all of them into one rule list.
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
	 * caller. Switched on with none chosen is no rules at all, as it is to the
	 * evaluator, so it no longer takes an element out of a shared cache by itself.
	 *
	 * @param array $settings Fragment settings.
	 * @return bool
	 */
	public static function conditions_enabled( array $settings ): bool {
		return ! empty( $settings[ self::ENABLED_KEY ] )
			&& 'yes' === $settings[ self::ENABLED_KEY ];
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

		// The access check the reference omits. The fallback's own page rules must
		// admit THIS visitor: the same evaluator, on the same rules, as the page itself.
		// A fallback whose stored rules cannot be read is unreadable and is not shown.
		$verdict = Agend_Content_Access_Access::verdict(
			Agend_Content_Access_Rules::from_post( $fallback_id ),
			array( 'anonymous' => $anonymous )
		);

		if ( ! $verdict['allowed'] ) {
			return '';
		}

		return trim( (string) apply_filters( 'the_content', $post->post_content ) );
	}
}
