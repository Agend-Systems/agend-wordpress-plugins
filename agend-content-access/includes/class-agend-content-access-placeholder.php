<?php
/**
 * The placeholder shown in place of a page the visitor may not read.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders what a denied visitor sees where the page body would have been.
 *
 * A page whose rules do not admit the visitor keeps its URL and shows a
 * placeholder in place of its body, unless its author chose to redirect them
 * instead (`Agend_Content_Access_Frontend::gate_request()`). The placeholder
 * replaces the 403 and the teaser page the gate used to answer with.
 *
 * SERVER-RENDERED, so it is the same in a block theme, a classic theme and on an
 * Elementor page, none of which has to know the plugin exists. It is built only
 * from what the gate knew: who the visitor is and why they were refused. It holds
 * no part of the page's own content except the manual excerpt, which is the
 * editor's deliberate public summary (an automatic one is the opening of the
 * protected body, so it is never used).
 *
 * THE COPY FOLLOWS WHY THE VISITOR WAS REFUSED, and only the words do. Who is
 * admitted was decided before this class is asked anything.
 *
 * | State                     | Heading                       | Buttons                          |
 * | ------------------------- | ----------------------------- | -------------------------------- |
 * | `authentication_required` | Sign in to read this          | Sign in; Join if a membership    |
 * |                           |                               | or plan rule is involved         |
 * | `membership_required`     | Join to read this             | Join                             |
 * | `plan_required`           | Available on another plan     | View membership options          |
 * | `access_restricted`       | This content is restricted    | none                             |
 *
 * The message under each heading is the gate copy the loop gate already uses
 * (`Agend_Content_Access_Frontend::gate_copy()`), including its naming of plans
 * and entitlements, so the two cannot drift. A segment is never named: its name can
 * describe the visitor. Any state this class does not know is treated as
 * `access_restricted`, the one with nothing to say and nothing to click.
 *
 * Sign in and Join go where Agend Apps Core's portal setting points. Core has no
 * separate membership address, so they share one destination, as the loop gate's
 * two calls to action always did. A button whose address is not configured is left
 * out, not pointed at nothing.
 *
 * `[agend_access_message]` renders the same heading, message and buttons for the
 * current denied request, so a site can put it in a synced pattern, restyle that,
 * and choose the pattern as a page's placeholder. The block pattern "Agend:
 * Members-only content" is that, ready made. Outside a denied request (an author
 * editing the pattern, a preview, a page that is not denied) it renders a neutral
 * preview that says what it is and names nobody and nothing.
 *
 * FILTERS (every one receives the post when there is one, and null in the
 * neutral preview):
 *
 *   agend_content_access_placeholder_heading  ( string $heading, string $state, ?WP_Post $post )
 *   agend_content_access_placeholder_message  ( string $message, string $state, ?WP_Post $post )
 *   agend_content_access_placeholder_buttons  ( array $buttons, string $state, ?WP_Post $post )
 *       Each button is `{label, url, kind}`; `kind` is `signin` or `join`.
 *   agend_content_access_placeholder_html     ( string $html, string $state, ?WP_Post $post )
 *       The final markup. Whatever is returned is shown to a visitor who was refused.
 */
class Agend_Content_Access_Placeholder {

	/**
	 * The shortcode that renders the message for the current denied request.
	 *
	 * @var string
	 */
	const SHORTCODE = 'agend_access_message';

	/**
	 * The block pattern's name and the category it is filed under.
	 *
	 * The category is `agend`, labelled "Agend": one place in the inserter for the
	 * patterns the Agend plugins ship.
	 *
	 * @var string
	 */
	const PATTERN          = 'agend-content-access/members-only';
	const PATTERN_CATEGORY = 'agend';

	/**
	 * Handle of the inline stylesheet, which is enqueued only when a placeholder
	 * is rendered.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'agend-content-access-placeholder';

	/**
	 * The state of the neutral preview, shown where there is no denied request.
	 *
	 * @var string
	 */
	const STATE_PREVIEW = 'preview';

	/**
	 * Deliberately plain: it inherits the theme's type and colour, borders use a
	 * mid grey that reads on light and dark, and the buttons wear core's
	 * `wp-element-button` class as well so a block theme styles them itself. A
	 * static string with no value from anywhere, so it is safe to inline.
	 *
	 * @var string
	 */
	const CSS = '.agend-access-placeholder{box-sizing:border-box;margin:2em 0;padding:1.5em;border:1px solid rgba(127,127,127,.4);border-radius:6px}'
		. '.agend-access-placeholder--preview{border-style:dashed}'
		. '.agend-access-placeholder__summary{margin-bottom:1.25em}'
		. '.agend-access-placeholder__summary p:last-child{margin-bottom:0}'
		. '.agend-access-placeholder__heading{margin:0 0 .5em}'
		. '.agend-access-placeholder__message{margin:0 0 1em}'
		. '.agend-access-placeholder__actions{display:flex;flex-wrap:wrap;gap:.75em;margin:0}'
		. '.agend-access-placeholder__button{display:inline-block;padding:.6em 1.2em;border:1px solid currentColor;border-radius:4px;text-decoration:none}';

	/**
	 * Registers the shortcode and the block pattern.
	 *
	 * Called from the bootstrap on every request: the shortcode runs wherever
	 * content does, and the pattern has to exist for the editor, which is a REST
	 * request.
	 */
	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_pattern' ) );
	}

	/**
	 * Registers the "Agend: Members-only content" block pattern.
	 *
	 * A core Group holding a core Shortcode block, so there is nothing of ours to
	 * keep in step with the editor: no custom block, no script. A site inserts it,
	 * restyles the Group, saves it as a synced pattern and chooses that pattern as
	 * a page's placeholder. The shortcode keeps the words and the links correct for
	 * the reason the visitor was refused.
	 */
	public static function register_pattern(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		if ( function_exists( 'register_block_pattern_category' ) ) {
			register_block_pattern_category(
				self::PATTERN_CATEGORY,
				array( 'label' => __( 'Agend', 'agend-content-access' ) )
			);
		}

		register_block_pattern(
			self::PATTERN,
			array(
				'title'       => __( 'Agend: Members-only content', 'agend-content-access' ),
				'description' => __( 'What a visitor sees in place of a page they are not allowed to read. Save it as a synced pattern, restyle it, then choose it as the placeholder in the page\'s access settings.', 'agend-content-access' ),
				'categories'  => array( self::PATTERN_CATEGORY ),
				'keywords'    => array( 'members', 'restricted', 'placeholder', 'sign in', 'join', 'agend' ),
				'content'     => self::pattern_content(),
			)
		);
	}

	/**
	 * The pattern's serialised blocks.
	 *
	 * @return string
	 */
	public static function pattern_content(): string {
		return '<!-- wp:group {"className":"agend-access-pattern","layout":{"type":"constrained"}} -->' . "\n"
			. '<div class="wp-block-group agend-access-pattern">' . "\n"
			. '<!-- wp:shortcode -->' . "\n"
			. '[' . self::SHORTCODE . ']' . "\n"
			. '<!-- /wp:shortcode -->' . "\n"
			. '</div>' . "\n"
			. '<!-- /wp:group -->';
	}

	/**
	 * The shortcode: the message for the current denied request, or a neutral
	 * preview when there is none.
	 *
	 * Reads the denial the page gate stored for this request, which is the state
	 * and the rules and nothing of the page's content. It takes no attributes: what
	 * to say is not the author's to set per use, the filters are for that.
	 *
	 * @param mixed  $atts    Attributes. Unused.
	 * @param string $content Enclosed content. Unused.
	 * @param string $tag     Shortcode tag. Unused.
	 * @return string
	 */
	public static function render_shortcode( $atts = array(), $content = '', $tag = '' ): string {
		$gate = Agend_Content_Access_Frontend::current_gate();

		return self::render( null === $gate ? null : $gate['decision'], null );
	}

	/**
	 * What a denied page shows in place of its body.
	 *
	 * The chosen public page, pattern or Elementor template when there is one this
	 * visitor may be shown, and the built-in placeholder in every other case. The
	 * chosen content goes through `Fragment_Rules::fallback_html()`, which is the
	 * one access-checked render: published, no password, a type that may be shown,
	 * and its own rules admit THIS visitor. A target that fails any of those, one
	 * that renders nothing, or the denied page itself, falls back to the built-in
	 * placeholder. Never to the page's own body.
	 *
	 * @param WP_Post $post      The page that is denied.
	 * @param array   $decision  Result of `Agend_Content_Access_Decision::for_post()`.
	 * @param int     $target_id Chosen placeholder content, 0 for the built-in one.
	 * @return string
	 */
	public static function for_page( WP_Post $post, array $decision, int $target_id = 0 ): string {
		// A page chosen as its own placeholder would print its body to the people it
		// was just refused to. Its own rules refuse them too, so the access check
		// below would catch it, but this is the one case where there is no doubt.
		if ( $target_id > 0 && $target_id !== (int) $post->ID && class_exists( 'Agend_Content_Access_Fragment_Rules', false ) ) {
			$html = Agend_Content_Access_Fragment_Rules::fallback_html( $target_id );

			if ( '' !== $html ) {
				return '<div class="agend-access-placeholder-content">' . $html . '</div>';
			}
		}

		return self::render( $decision, $post );
	}

	/**
	 * The built-in placeholder: the summary when there is one, then the message.
	 *
	 * @param array|null   $decision Result of `Agend_Content_Access_Decision::for_post()`, or null for
	 *                               the neutral preview.
	 * @param WP_Post|null $post     The denied page, for its summary. Null for none.
	 * @return string
	 */
	public static function render( ?array $decision, ?WP_Post $post = null ): string {
		$copy = self::copy( $decision, $post );

		self::enqueue_style();

		$html = '<div class="agend-access-placeholder agend-access-placeholder--' . esc_attr( $copy['state'] ) . '">';

		// The manual excerpt only: an automatic one is the first words of the body.
		// Only for a real denial. The neutral preview names no page.
		$summary = null !== $decision && null !== $post ? Agend_Content_Access_Frontend::teaser_for( $post ) : '';

		if ( '' !== $summary ) {
			$html .= '<div class="agend-access-placeholder__summary">' . self::inert_paragraphs( $summary ) . '</div>';
		}

		$html .= '<div class="agend-access-placeholder__prompt">';
		$html .= '<h2 class="agend-access-placeholder__heading">' . esc_html( $copy['heading'] ) . '</h2>';
		$html .= '<p class="agend-access-placeholder__message">' . esc_html( $copy['message'] ) . '</p>';

		if ( array() !== $copy['buttons'] ) {
			$html .= '<p class="agend-access-placeholder__actions">';

			foreach ( $copy['buttons'] as $button ) {
				$html .= '<a class="agend-access-placeholder__button agend-access-placeholder__button--' . esc_attr( $button['kind'] ) . ' wp-element-button" href="' . esc_url( $button['url'] ) . '">'
					. esc_html( $button['label'] ) . '</a>';
			}

			$html .= '</p>';
		}

		$html .= '</div></div>';

		/**
		 * Filters the placeholder markup shown to a visitor who was refused.
		 *
		 * Whatever is returned is shown to somebody the page's rules do not admit,
		 * so it must contain nothing from the page's body. The state is
		 * `authentication_required`, `membership_required`, `plan_required`,
		 * `access_restricted`, or `preview` outside a denied request.
		 *
		 * @param string       $html  Placeholder markup.
		 * @param string       $state The denial state, or `preview`.
		 * @param WP_Post|null $post  The denied page, or null in the preview and from the shortcode.
		 */
		return (string) apply_filters( 'agend_content_access_placeholder_html', $html, $copy['state'], $post );
	}

	/**
	 * The heading, message and buttons for a denial, with the filters applied.
	 *
	 * @param array|null   $decision Result of `Agend_Content_Access_Decision::for_post()`, or null for
	 *                               the neutral preview.
	 * @param WP_Post|null $post     The denied page.
	 * @return array{state: string, heading: string, message: string, buttons: array<int, array{label: string, url: string, kind: string}>}
	 */
	public static function copy( ?array $decision, ?WP_Post $post = null ): array {
		$buttons = array();

		if ( null === $decision ) {
			$state   = self::STATE_PREVIEW;
			$heading = __( 'Members-only content placeholder', 'agend-content-access' );
			$message = __( 'Visitors who are not allowed to see this page will see a message here, with sign in or join options when they apply.', 'agend-content-access' );
		} else {
			$state  = self::known_state( isset( $decision['state'] ) ? (string) $decision['state'] : '' );
			$policy = isset( $decision['policy'] ) && is_array( $decision['policy'] ) ? $decision['policy'] : null;
			$gate   = Agend_Content_Access_Frontend::gate_copy( $state, $policy );
			$link   = Agend_Content_Access_Frontend::portal_link();

			$message = $gate['message'];

			switch ( $state ) {
				case Agend_Content_Access_Decision::STATE_AUTH:
					$heading = __( 'Sign in to read this', 'agend-content-access' );

					$buttons[] = self::button( $gate['cta'], $link, 'signin' );

					// Signed out is not the whole story when the page is for members: a
					// visitor with no account needs a way to become one.
					if ( self::offers_membership( $decision ) ) {
						$buttons[] = self::button( __( 'Join', 'agend-content-access' ), $link, 'join' );
					}
					break;
				case Agend_Content_Access_Decision::STATE_MEMBERSHIP:
					$heading   = __( 'Join to read this', 'agend-content-access' );
					$buttons[] = self::button( __( 'Join', 'agend-content-access' ), $link, 'join' );
					break;
				case Agend_Content_Access_Decision::STATE_PLAN:
					$heading   = __( 'Available on another plan', 'agend-content-access' );
					$buttons[] = self::button( $gate['cta'], $link, 'join' );
					break;
				default:
					// Restricted, and any state not known: a heading with nothing to name
					// and nothing to click. A segment is not named here or anywhere.
					$heading = __( 'This content is restricted', 'agend-content-access' );
					break;
			}

			$buttons = array_values( array_filter( $buttons ) );
		}

		/**
		 * Filters the placeholder's heading.
		 *
		 * @param string       $heading Heading text. Escaped on output.
		 * @param string       $state   The denial state, or `preview`.
		 * @param WP_Post|null $post    The denied page, or null.
		 */
		$heading = (string) apply_filters( 'agend_content_access_placeholder_heading', $heading, $state, $post );

		/**
		 * Filters the placeholder's message.
		 *
		 * @param string       $message Message text. Escaped on output.
		 * @param string       $state   The denial state, or `preview`.
		 * @param WP_Post|null $post    The denied page, or null.
		 */
		$message = (string) apply_filters( 'agend_content_access_placeholder_message', $message, $state, $post );

		/**
		 * Filters the placeholder's buttons.
		 *
		 * @param array        $buttons Each `{label, url, kind}`. An entry that is not that shape is dropped.
		 * @param string       $state   The denial state, or `preview`.
		 * @param WP_Post|null $post    The denied page, or null.
		 */
		$buttons = apply_filters( 'agend_content_access_placeholder_buttons', $buttons, $state, $post );

		return array(
			'state'   => $state,
			'heading' => $heading,
			'message' => $message,
			'buttons' => self::checked_buttons( $buttons ),
		);
	}

	/**
	 * Enqueues the placeholder's stylesheet, once, and only because a placeholder
	 * is being rendered.
	 *
	 * Inline, with no file to fetch. When this runs after `wp_head` (the shortcode,
	 * which runs inside the page's content) WordPress prints the style in the
	 * footer, which is late but present.
	 */
	public static function enqueue_style(): void {
		if ( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		wp_register_style( self::STYLE_HANDLE, false );
		wp_add_inline_style( self::STYLE_HANDLE, self::CSS );
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Whether a signed-out visitor is also worth offering "Join" to.
	 *
	 * @param array $decision Result of `Agend_Content_Access_Decision::for_post()`.
	 * @return bool
	 */
	private static function offers_membership( array $decision ): bool {
		return isset( $decision['rules'] )
			&& $decision['rules'] instanceof Agend_Content_Access_Rules
			&& class_exists( 'Agend_Content_Access_Access', false )
			&& Agend_Content_Access_Access::offers_membership( $decision['rules'] );
	}

	/**
	 * A denial state this class has copy for.
	 *
	 * @param string $state A state, possibly one a later release adds.
	 * @return string The state, or `access_restricted` for any other.
	 */
	private static function known_state( string $state ): string {
		$known = array(
			Agend_Content_Access_Decision::STATE_AUTH,
			Agend_Content_Access_Decision::STATE_MEMBERSHIP,
			Agend_Content_Access_Decision::STATE_PLAN,
			Agend_Content_Access_Decision::STATE_RESTRICTED,
		);

		return in_array( $state, $known, true ) ? $state : Agend_Content_Access_Decision::STATE_RESTRICTED;
	}

	/**
	 * One button, or null when it has nowhere to go.
	 *
	 * @param string $label Button text.
	 * @param string $url   Where it goes.
	 * @param string $kind  `signin` or `join`.
	 * @return array{label: string, url: string, kind: string}|null
	 */
	private static function button( string $label, string $url, string $kind ): ?array {
		if ( '' === $label || '' === $url ) {
			return null;
		}

		return array(
			'label' => $label,
			'url'   => $url,
			'kind'  => $kind,
		);
	}

	/**
	 * The buttons a filter returned, with anything that is not a labelled link
	 * dropped.
	 *
	 * @param mixed $buttons Filtered value.
	 * @return array<int, array{label: string, url: string, kind: string}>
	 */
	private static function checked_buttons( $buttons ): array {
		$checked = array();

		foreach ( is_array( $buttons ) ? $buttons : array() as $button ) {
			if (
				! is_array( $button )
				|| ! isset( $button['label'], $button['url'] )
				|| ! is_string( $button['label'] )
				|| ! is_string( $button['url'] )
				|| '' === $button['label']
				|| '' === esc_url( $button['url'] )
			) {
				continue;
			}

			$kind = isset( $button['kind'] ) && is_string( $button['kind'] ) ? sanitize_html_class( $button['kind'] ) : '';

			$checked[] = array(
				'label' => $button['label'],
				'url'   => $button['url'],
				'kind'  => '' === $kind ? 'link' : $kind,
			);
		}

		return $checked;
	}

	/**
	 * An editor's summary as paragraphs, with nothing in it left to run.
	 *
	 * Escaped, and its square brackets written as entities: the placeholder goes
	 * through `the_content`, whose shortcode pass would otherwise run a shortcode
	 * somebody typed into a summary, on a page the visitor was refused. The
	 * newlines `wpautop()` adds are removed because the content filters run on this
	 * markup again and would turn each into a line break.
	 *
	 * @param string $text Summary text.
	 * @return string
	 */
	private static function inert_paragraphs( string $text ): string {
		$escaped = str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), esc_html( $text ) );

		return str_replace( "\n", '', wpautop( $escaped ) );
	}
}
