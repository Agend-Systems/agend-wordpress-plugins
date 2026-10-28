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
	 * The kinds of content that can be shown in place of something hidden, and how
	 * each is rendered: a page through `the_content`, a synced pattern through
	 * `do_blocks()`, an Elementor template through Elementor's own frontend.
	 *
	 * @var string
	 */
	const KIND_PAGE               = 'page';
	const KIND_PATTERN            = 'pattern';
	const KIND_ELEMENTOR_TEMPLATE = 'elementor_template';

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
	 * Only content of a kind that can be shown is. A post type that can carry rules
	 * is treated as public when it has none, and that reading is only safe for the
	 * types the rule panel is offered on: a post type that cannot carry a rule has
	 * never had one, so "no rules" would mean nothing about it and an id typed by
	 * hand could print a private post type's body. The three kinds are a page of a
	 * type the panel supports, a synced pattern (`wp_block`) and, when Elementor is
	 * active, an Elementor template (`elementor_library`). Anything else renders
	 * nothing, as does a template when Elementor is not there to render it.
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

		$kind = self::kind_of( $post );

		if ( '' === $kind ) {
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

		switch ( $kind ) {
			case self::KIND_PATTERN:
				// The block pass alone leaves a shortcode in the pattern as text: they
				// are run by `the_content`'s own pass, which a pattern is not given.
				return trim( do_shortcode( do_blocks( $post->post_content ) ) );
			case self::KIND_ELEMENTOR_TEMPLATE:
				return trim( self::elementor_template_html( (int) $post->ID ) );
			default:
				return trim( (string) apply_filters( 'the_content', $post->post_content ) );
		}
	}

	/**
	 * Which kind of fallback a post is, or an empty string when it is none.
	 *
	 * @param WP_Post $post The chosen post.
	 * @return string One of the `KIND_*` constants, or ''.
	 */
	public static function kind_of( WP_Post $post ): string {
		if ( 'wp_block' === $post->post_type ) {
			return self::KIND_PATTERN;
		}

		// Checked before the supported types: a site can make templates public.
		if ( 'elementor_library' === $post->post_type ) {
			return self::elementor_is_active() ? self::KIND_ELEMENTOR_TEMPLATE : '';
		}

		return in_array( $post->post_type, Agend_Content_Access_Meta_Box::supported_post_types(), true )
			? self::KIND_PAGE
			: '';
	}

	/**
	 * Whether Elementor has finished loading.
	 *
	 * Asked through the plugin's own probe when it is declared, and false when it
	 * is not: a request that cannot say Elementor is there does not render its
	 * templates.
	 *
	 * @return bool
	 */
	public static function elementor_is_active(): bool {
		return function_exists( 'agend_content_access_has_elementor' ) && agend_content_access_has_elementor();
	}

	/**
	 * An Elementor template's rendered content, through Elementor's own frontend.
	 *
	 * Guarded at every step: Elementor not loaded, its frontend not there, or the
	 * method missing from a version that renamed it, each render nothing rather than
	 * fatal on a page the visitor is already being refused. The template's own
	 * section rules are enforced as for any Elementor render, for this visitor.
	 *
	 * @param int $template_id Template post id.
	 * @return string The markup, or '' when it cannot be rendered.
	 */
	private static function elementor_template_html( int $template_id ): string {
		if ( ! self::elementor_is_active() || ! class_exists( '\Elementor\Plugin', false ) ) {
			return '';
		}

		$plugin   = \Elementor\Plugin::$instance;
		$frontend = is_object( $plugin ) && isset( $plugin->frontend ) ? $plugin->frontend : null;

		if ( ! is_object( $frontend ) || ! method_exists( $frontend, 'get_builder_content_for_display' ) ) {
			return '';
		}

		// With its CSS, so a template renders styled when it is the only thing Elementor
		// is asked for on the page.
		return (string) $frontend->get_builder_content_for_display( $template_id, true );
	}
}
